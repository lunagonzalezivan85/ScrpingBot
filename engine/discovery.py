import time
from urllib.parse import urlparse
from typing import List, Set
from ddgs import DDGS

# Dominios que se deben descartar porque no son páginas de contacto ni directorios útiles
DOMINIOS_IGNORADOS: Set[str] = {
    'facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com',
    'youtube.com', 'wikipedia.org', 'tripadvisor.com', 'tiktok.com', 'amazon.com',
    'pinterest.com', 'reddit.com', 'yahoo.com', 'bing.com', 'google.com'
}

def generar_consultas_busqueda(sector: str, ciudad: str, pais: str = "Nicaragua") -> List[str]:
    """Genera queries optimizadas para encontrar prestamistas y microcréditos locales."""
    return [
        f"{sector} {ciudad} {pais}",
        f"prestamos {ciudad} nicaragua",
        f"microfinancieras en {ciudad}",
        f"financiera credito {ciudad} nicaragua",
    ]

def descubrir_urls_candidatas(sector: str, ciudad: str, pais: str = "Nicaragua", max_urls: int = 10) -> List[str]:
    """
    Realiza búsquedas en la web y devuelve una lista curada de URLs reales
    descartando redes sociales cerradas y duplicados de dominio.
    """
    consultas = generar_consultas_busqueda(sector, ciudad, pais)
    urls_resultado: List[str] = []
    dominios_vistos: Set[str] = set()

    for query in consultas:
        if len(urls_resultado) >= max_urls:
            break
        try:
            with DDGS() as ddgs:
                # DDGS funciona mucho mejor solicitando al menos 10 resultados
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

                    # Ignorar redes sociales cerradas
                    if any(ign in dominio_base for ign in DOMINIOS_IGNORADOS):
                        continue

                    # Si es un dominio nuevo o directorio con subpáginas
                    if dominio_base not in dominios_vistos or "paginasamarillas" in dominio_base:
                        dominios_vistos.add(dominio_base)
                        urls_resultado.append(url)

                    if len(urls_resultado) >= max_urls:
                        break

        except Exception as e:
            time.sleep(1)
            continue

    return urls_resultado
