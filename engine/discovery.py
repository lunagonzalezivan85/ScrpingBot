import time
from urllib.parse import urlparse
from typing import List, Set
from ddgs import DDGS

# Dominios que se deben descartar porque no son páginas de contacto ni sitios de empresas
DOMINIOS_IGNORADOS: Set[str] = {
    'facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com',
    'youtube.com', 'wikipedia.org', 'tripadvisor.com', 'tiktok.com', 'amazon.com',
    'pinterest.com', 'reddit.com', 'yahoo.com', 'bing.com', 'google.com',
    'aeroleads.com', 'bytescraper.com', 'bestprosintown.com', 'zoominfo.com',
    'apollo.io', 'dnb.com', 'bbb.org'
}

def generar_consultas_busqueda(sector: str, ciudad: str, pais: str = "Estados Unidos") -> List[str]:
    """
    Genera consultas de búsqueda optimizadas para encontrar sitios directos de empresas.
    """
    pais_lower = (pais or "").lower().strip()
    es_usa = any(u in pais_lower for u in ['estados unidos', 'usa', 'united states', 'eeuu', 'us'])

    consultas = []

    if es_usa:
        # Búsquedas para encontrar sitios web directos en EE.UU.
        consultas.extend([
            f"{sector} in {ciudad} contact phone website",
            f"{sector} companies in {ciudad} official site",
            f"top {sector} contractors {ciudad} phone",
            f"{sector} business {ciudad} contact us",
        ])
    else:
        # Búsquedas generales para países de habla hispana
        consultas.extend([
            f"empresas de {sector} en {ciudad} {pais} contacto telefono",
            f"{sector} en {ciudad} {pais} sitio oficial",
            f"directorio {sector} {ciudad} telefono",
            f"{sector} {ciudad} {pais} contacto",
        ])

    return consultas

def descubrir_urls_candidatas(sector: str, ciudad: str, pais: str = "Estados Unidos", max_urls: int = 10) -> List[str]:
    """
    Realiza búsquedas en la web y devuelve una lista curada de URLs reales
    descartando redes sociales cerradas y agregadores bloqueados.
    """
    consultas = generar_consultas_busqueda(sector, ciudad, pais)
    urls_resultado: List[str] = []
    dominios_vistos: Set[str] = set()

    for query in consultas:
        if len(urls_resultado) >= max_urls:
            break
        try:
            with DDGS() as ddgs:
                resultados = list(ddgs.text(query, max_results=15))
                if not resultados:
                    continue

                for item in resultados:
                    url = item.get("href")
                    if not url:
                        continue

                    try:
                        netloc = urlparse(url).netloc.lower()
                        dominio_base = netloc.replace("www.", "")
                    except Exception:
                        continue

                    if any(ign in dominio_base for ign in DOMINIOS_IGNORADOS):
                        continue

                    if dominio_base not in dominios_vistos:
                        dominios_vistos.add(dominio_base)
                        urls_resultado.append(url)

                    if len(urls_resultado) >= max_urls:
                        break

        except Exception as e:
            time.sleep(1)
            continue

    return urls_resultado
