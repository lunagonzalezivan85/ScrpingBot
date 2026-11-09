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
from normalizer import normalizar_telefono_nicaragua, limpiar_texto
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

def extraccion_fallback_html(url: str, sector: str, ciudad: str) -> Optional[LeadExtracted]:
    """
    Extractor rápido de contingencia: descarga el HTML y busca patrones de teléfono
    de Nicaragua (+505 o números de 8 dígitos que empiecen por 2, 5, 7 u 8).
    Permite probar el sistema incluso si no se ha configurado una clave de LLM aún.
    """
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
    }
    try:
        resp = requests.get(url, headers=headers, timeout=10)
        if resp.status_code != 200:
            return None

        soup = BeautifulSoup(resp.text, 'html.parser')
        
        # Obtener título o nombre probable
        titulo = soup.title.string.strip() if soup.title and soup.title.string else ""
        nombre = titulo.split("|")[0].split("-")[0].strip() or f"Entidad en {ciudad}"

        # Buscar enlaces tel:
        tel_found = None
        for a in soup.find_all('a', href=True):
            if a['href'].startswith('tel:'):
                candidato = a['href'].replace('tel:', '').strip()
                t_e164, _ = normalizar_telefono_nicaragua(candidato)
                if t_e164:
                    tel_found = t_e164
                    break

        # Si no hubo enlace tel:, buscar con expresión regular en el texto
        if not tel_found:
            texto = soup.get_text()
            # Patrón para teléfonos de Nicaragua (8 dígitos o con +505)
            matches = re.findall(r'(?:\+?505[\s-]?)?([2578]\d{3}[\s-]?\d{4})', texto)
            for m in matches:
                t_e164, _ = normalizar_telefono_nicaragua(m)
                if t_e164:
                    tel_found = t_e164
                    break

        if tel_found:
            return LeadExtracted(
                nombre_empresa=nombre[:100],
                tipo_entidad="Microfinanciera / Empresa" if "financiera" in nombre.lower() or "credito" in nombre.lower() else "No especificado",
                telefono=tel_found,
                direccion=f"{ciudad}, Nicaragua",
                detalles_oferta=f"Identificado automáticamente desde {url}"
            )
    except Exception as e:
        print(f"[Fallback error en {url}] {e}")
    return None

def procesar_scraping(job_id: int, sector: str, ciudad: str, limite: int, api_key: str):
    conn = obtener_db_conexion()
    actualizar_estado_job(conn, job_id, "running")
    registrar_log(conn, job_id, "info", f"Iniciando prospección para sector '{sector}' en '{ciudad}', límite: {limite}")

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
        registrar_log(conn, job_id, "warning", "No se detectó API Key de LLM. Usando extractor HTML directo (agrega tu clave en engine/.env para IA semántica completa).")

    # 1. Descubrimiento de URLs candidatas
    registrar_log(conn, job_id, "info", "Buscando entidades y anuncios públicos en internet...")
    urls_candidatas = descubrir_urls_candidatas(sector, ciudad, "Nicaragua", max_urls=limite * 3)

    if not urls_candidatas:
        msg = f"No se encontraron enlaces para '{sector}' en '{ciudad}'."
        registrar_log(conn, job_id, "warning", msg)
        actualizar_estado_job(conn, job_id, "completed", 0, msg)
        conn.close()
        return

    registrar_log(conn, job_id, "info", f"Se encontraron {len(urls_candidatas)} URLs candidatas para auditar.")
    leads_guardados = 0

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
                prompt = (
                    f"Analiza la información de esta página sobre '{sector}' en '{ciudad}', Nicaragua. "
                    "Extrae los datos de contacto: nombre comercial o de la persona que ofrece préstamos/servicios, "
                    "tipo de entidad, teléfono o WhatsApp de 8 dígitos de Nicaragua, correo, persona de contacto, "
                    "dirección física y detalles breves de la oferta o condiciones."
                )
                scraper = SmartScraperGraph(
                    prompt=prompt,
                    source=url,
                    schema=LeadExtracted,
                    config=graph_config
                )
                res = scraper.run()
                if res:
                    lead = LeadExtracted(**res)
            except Exception as e:
                err_msg = str(e)
                registrar_log(conn, job_id, "warning", f"IA falló en {url} ({err_msg}). Intentando con extractor de respaldo...")
                lead = extraccion_fallback_html(url, sector, ciudad)
        else:
            lead = extraccion_fallback_html(url, sector, ciudad)

        if not lead or not lead.telefono:
            registrar_log(conn, job_id, "warning", f"Sin teléfono válido en {url}")
            continue

        tel_e164, tel_crudo = normalizar_telefono_nicaragua(lead.telefono)
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
                    (job_id, sector, ciudad, departamento, nombre_empresa, tipo_entidad, 
                     telefono, telefono_raw, email, persona_contacto, direccion, sitio_web, detalles_oferta)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    """,
                    (
                        job_id, sector, ciudad, "Chinandega", lead.nombre_empresa, lead.tipo_entidad,
                        tel_e164, tel_crudo, lead.email, lead.persona_contacto, 
                        lead.direccion or ciudad, url, lead.detalles_oferta
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
    parser.add_argument("--sector", type=str, default="Microcréditos y préstamos")
    parser.add_argument("--ciudad", type=str, default="Chinandega")
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
        limite = job_data["limite_solicitado"]
    else:
        sector = args.sector
        ciudad = args.ciudad
        limite = args.limite
        job_uuid = str(uuid.uuid4())
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO scraping_jobs (uuid, sector, ciudad, limite_solicitado, status)
                VALUES (%s, %s, %s, %s, 'pending')
                """,
                (job_uuid, sector, ciudad, limite)
            )
            job_id = cur.lastrowid
        conn.commit()

    conn.close()
    procesar_scraping(job_id, sector, ciudad, limite, args.api_key)

if __name__ == "__main__":
    main()
