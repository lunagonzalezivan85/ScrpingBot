import argparse
import sys
import time
import uuid
import re
from datetime import datetime
from typing import Optional, Dict, Any, List

import pymysql
from pymysql.cursors import DictCursor
import requests
from bs4 import BeautifulSoup

from config import config
from models import LeadExtracted
from normalizer import normalizar_telefono_internacional, limpiar_texto
from discovery import descubrir_urls_candidatas

def obtener_db_conexion():
    """Conecta a la base de datos MySQL en XAMPP."""
    return pymysql.connect(
        host=config.DB_HOST,
        port=config.DB_PORT,
        user=config.DB_USER,
        password=config.DB_PASSWORD,
        database=config.DB_NAME,
        charset='utf8mb4',
        cursorclass=DictCursor
    )

def registrar_log(conn, job_id: int, nivel: str, mensaje: str, contexto: Optional[str] = None):
    """Inserta una traza de log en la tabla job_logs."""
    try:
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO job_logs (job_id, nivel, mensaje, datos_contexto) VALUES (%s, %s, %s, %s)",
                (job_id, nivel, mensaje, contexto)
            )
        conn.commit()
    except Exception as e:
        print(f"[Error escribiendo log] {e}")

def actualizar_estado_job(conn, job_id: int, status: str, leads_guardados: int = 0, error_msg: Optional[str] = None):
    """Actualiza los contadores y estado de un scraping_job."""
    try:
        with conn.cursor() as cur:
            if status == "running":
                cur.execute(
                    "UPDATE scraping_jobs SET status = 'running', iniciado_en = NOW() WHERE id = %s",
                    (job_id,)
                )
            elif status in ("completed", "failed"):
                cur.execute(
                    """
                    UPDATE scraping_jobs 
                    SET status = %s, leads_guardados = %s, error_mensaje = %s, finalizado_en = NOW()
                    WHERE id = %s
                    """,
                    (status, leads_guardados, error_msg, job_id)
                )
        conn.commit()
    except Exception as e:
        print(f"[Error actualizando job] {e}")

def extraccion_fallback_html(url: str, sector: str, ciudad: str, pais: str) -> Optional[LeadExtracted]:
    """
    Extractor de contingencia: descarga el HTML y busca patrones de teléfono
    válidos según el país especificado.
    """
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
    }
    try:
        resp = requests.get(url, headers=headers, timeout=12)
        if resp.status_code != 200:
            return None

        soup = BeautifulSoup(resp.text, 'html.parser')
        titulo = soup.title.string.strip() if soup.title and soup.title.string else ""
        nombre = titulo.split("|")[0].split("-")[0].strip() or f"Empresa en {ciudad}"

        tel_found = None
        for a in soup.find_all('a', href=True):
            if a['href'].startswith('tel:'):
                candidato = a['href'].replace('tel:', '').strip()
                t_e164, _ = normalizar_telefono_internacional(candidato, pais)
                if t_e164:
                    tel_found = t_e164
                    break

        if not tel_found:
            texto = soup.get_text()
            matches = re.findall(r'(?:\+?1[\s-]?)?\(?\d{3}\)?[\s-]?\d{3}[\s-]?\d{4}|\+?\d{8,14}', texto)
            for m in matches:
                t_e164, _ = normalizar_telefono_internacional(m, pais)
                if t_e164:
                    tel_found = t_e164
                    break

        if tel_found:
            return LeadExtracted(
                nombre_empresa=nombre[:100],
                tipo_entidad="Empresa / Negocio",
                telefono=tel_found,
                direccion=f"{ciudad}, {pais}",
                detalles_oferta=f"Identificado automáticamente desde {url}"
            )
    except Exception as e:
        print(f"[Fallback error en {url}] {e}")
    return None

def procesar_scraping(job_id: int, sector: str, ciudad: str, pais: str, limite: int, api_key: str):
    conn = obtener_db_conexion()
    actualizar_estado_job(conn, job_id, "running")
    registrar_log(conn, job_id, "info", f"Iniciando prospección para '{sector}' en '{ciudad}' ({pais}), límite: {limite}")

    llm_key = api_key or config.API_KEY
    tiene_llm = bool(llm_key or config.LLM_PROVIDER == "ollama")

    if tiene_llm:
        registrar_log(conn, job_id, "info", f"Modo IA activo con modelo: {config.LLM_MODEL}")
        llm_cfg = {
            "model": config.LLM_MODEL,
            "temperature": 0,
        }
        if llm_key:
            llm_cfg["api_key"] = llm_key
        if config.LLM_PROVIDER == "ollama":
            llm_cfg["base_url"] = config.OLLAMA_BASE_URL
            llm_cfg["format"] = "json"

        graph_config = {
            "llm": llm_cfg,
            "headless": True,
            "verbose": False
        }
    else:
        registrar_log(conn, job_id, "warning", "No se detectó API Key de LLM. Usando extractor HTML directo.")

    # 1. Descubrimiento de URLs candidatas
    registrar_log(conn, job_id, "info", f"Buscando empresas de '{sector}' en {ciudad}, {pais}...")
    urls_candidatas = descubrir_urls_candidatas(sector, ciudad, pais, max_urls=limite * 3)

    if not urls_candidatas:
        msg = f"No se encontraron enlaces para '{sector}' en '{ciudad}, {pais}'."
        registrar_log(conn, job_id, "warning", msg)
        actualizar_estado_job(conn, job_id, "completed", 0, msg)
        conn.close()
        return

    registrar_log(conn, job_id, "info", f"Se encontraron {len(urls_candidatas)} URLs candidatas para auditar.")
    leads_guardados = 0

    pais_lower = (pais or "").lower().strip()
    es_ingles = any(u in pais_lower for u in ['estados unidos', 'usa', 'united states', 'eeuu', 'us', 'canada', 'canadá', 'uk'])

    # 2. Procesar cada URL
    for idx, url in enumerate(urls_candidatas, 1):
        if leads_guardados >= limite:
            registrar_log(conn, job_id, "info", f"Meta de {limite} prospectos alcanzada.")
            break

        print(f"[{idx}/{len(urls_candidatas)}] Analizando: {url}")
        registrar_log(conn, job_id, "info", f"Analizando: {url}")

        lead = None

        if tiene_llm:
            try:
                from scrapegraphai.graphs import SmartScraperGraph

                # Ajuste de idioma del prompt según el país de la empresa
                if es_ingles:
                    prompt = (
                        f"Extract contact and business information for this {sector} company in {ciudad}, {pais}: "
                        "company name, phone number (with area code), email address, contact person name, "
                        "full street address, and a brief summary of services offered."
                    )
                else:
                    prompt = (
                        f"Extrae la información de contacto de esta empresa de '{sector}' en '{ciudad}', {pais}: "
                        "nombre comercial de la empresa, teléfono de contacto con código de área, "
                        "correo electrónico, persona de contacto, dirección física completa "
                        "y resumen de los servicios ofrecidos."
                    )

                scraper = SmartScraperGraph(
                    prompt=prompt,
                    source=url,
                    schema=LeadExtracted,
                    config=graph_config
                )
                res = scraper.run()
                if res and res.get('nombre_empresa') and res.get('nombre_empresa') != 'NA':
                    lead = LeadExtracted(**res)
            except Exception as e:
                err_msg = str(e)
                registrar_log(conn, job_id, "warning", f"IA falló en {url} ({err_msg}). Intentando con extractor de respaldo...")
                lead = extraccion_fallback_html(url, sector, ciudad, pais)
        else:
            lead = extraccion_fallback_html(url, sector, ciudad, pais)

        if not lead or not lead.telefono:
            registrar_log(conn, job_id, "warning", f"Sin teléfono válido detectado en {url}")
            continue

        tel_e164, tel_crudo = normalizar_telefono_internacional(lead.telefono, pais)
        if not tel_e164:
            registrar_log(conn, job_id, "warning", f"Teléfono descartado por formato ({lead.telefono})")
            continue

        # Guardar en MySQL
        try:
            with conn.cursor() as cur:
                # Comprobar duplicado
                cur.execute("SELECT id FROM leads WHERE telefono = %s", (tel_e164,))
                if cur.fetchone():
                    registrar_log(conn, job_id, "info", f"El teléfono {tel_e164} ya estaba registrado en la BD.")
                    continue

                cur.execute(
                    """
                    INSERT INTO leads 
                    (job_id, sector, ciudad, departamento, pais, nombre_empresa, tipo_entidad, 
                     telefono, telefono_raw, email, persona_contacto, direccion, sitio_web, detalles_oferta)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    """,
                    (
                        job_id, sector, ciudad, ciudad, pais, lead.nombre_empresa, lead.tipo_entidad or "Empresa",
                        tel_e164, tel_crudo, lead.email, lead.persona_contacto, 
                        lead.direccion or f"{ciudad}, {pais}", url, lead.detalles_oferta
                    )
                )
            conn.commit()

            leads_guardados += 1
            registrar_log(conn, job_id, "success", f"Guardado: {lead.nombre_empresa} -> {tel_e164}")
            print(f"✅ Guardado lead #{leads_guardados}: {lead.nombre_empresa} -> {tel_e164}")

        except Exception as e:
            registrar_log(conn, job_id, "error", f"Error guardando lead: {e}")

        time.sleep(1)

    actualizar_estado_job(conn, job_id, "completed", leads_guardados)
    registrar_log(conn, job_id, "success", f"Prospección finalizada. Total leads nuevos guardados: {leads_guardados}")
    conn.close()
    print(f"\n🎉 Proceso completado. Leads guardados: {leads_guardados}")

def main():
    parser = argparse.ArgumentParser(description="Worker de Prospección Scraph")
    parser.add_argument("--job_id", type=int, help="ID del trabajo en la base de datos")
    parser.add_argument("--sector", type=str, default="Construcción")
    parser.add_argument("--ciudad", type=str, default="Miami")
    parser.add_argument("--pais", type=str, default="Estados Unidos")
    parser.add_argument("--limite", type=int, default=5)
    parser.add_argument("--api_key", type=str, default="")
    args = parser.parse_args()

    conn = obtener_db_conexion()

    if args.job_id:
        job_id = args.job_id
        with conn.cursor() as cur:
            cur.execute("SELECT * FROM scraping_jobs WHERE id = %s", (job_id,))
            job_data = cur.fetchone()
        if not job_data:
            print(f"Error: Job con ID {job_id} no existe.")
            sys.exit(1)
        sector = job_data["sector"]
        ciudad = job_data["ciudad"]
        pais   = job_data.get("pais") or "Estados Unidos"
        limite = job_data["limite_solicitado"]
    else:
        sector = args.sector
        ciudad = args.ciudad
        pais   = args.pais
        limite = args.limite
        job_uuid = str(uuid.uuid4())
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO scraping_jobs (uuid, sector, ciudad, pais, limite_solicitado, status)
                VALUES (%s, %s, %s, %s, %s, 'pending')
                """,
                (job_uuid, sector, ciudad, pais, limite)
            )
            job_id = cur.lastrowid
        conn.commit()

    conn.close()
    procesar_scraping(job_id, sector, ciudad, pais, limite, args.api_key)

if __name__ == "__main__":
    main()
