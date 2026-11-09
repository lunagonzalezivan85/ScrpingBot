import re
from typing import Optional, Tuple
import phonenumbers

# Mapa de países comunes a códigos ISO de 2 letras
MAPA_PAISES = {
    'estados unidos': 'US',
    'united states': 'US',
    'usa': 'US',
    'eeuu': 'US',
    'us': 'US',
    'nicaragua': 'NI',
    'ni': 'NI',
    'mexico': 'MX',
    'méxico': 'MX',
    'mx': 'MX',
    'espana': 'ES',
    'españa': 'ES',
    'spain': 'ES',
    'es': 'ES',
    'colombia': 'CO',
    'costa rica': 'CR',
    'honduras': 'HN',
    'guatemala': 'GT',
    'el salvador': 'SV',
    'panama': 'PA',
    'panamá': 'PA',
    'argentina': 'AR',
    'chile': 'CL',
    'peru': 'PE',
    'perú': 'PE',
    'canada': 'CA',
    'canadá': 'CA',
}

def normalizar_telefono_internacional(tel_raw: Optional[str], pais: str = "Nicaragua") -> Tuple[Optional[str], Optional[str]]:
    """
    Limpia y valida un número telefónico de cualquier país usando Google phonenumbers.
    Retorna una tupla: (telefono_normalizado_e164, telefono_crudo_limpio).
    """
    if not tel_raw:
        return None, None

    tel_limpio = tel_raw.strip()
    pais_normalizado = (pais or "Nicaragua").lower().strip()
    codigo_region = MAPA_PAISES.get(pais_normalizado, 'US')

    try:
        # Intentar parsear con el código de región esperado
        num_obj = phonenumbers.parse(tel_limpio, codigo_region)
        if phonenumbers.is_possible_number(num_obj):
            tel_e164 = phonenumbers.format_number(num_obj, phonenumbers.PhoneNumberFormat.E164)
            return tel_e164, tel_limpio
    except Exception:
        pass

    # Si empieza con '+', intentar parsearlo directamente
    if tel_limpio.startswith('+'):
        try:
            num_obj = phonenumbers.parse(tel_limpio, None)
            if phonenumbers.is_possible_number(num_obj):
                return phonenumbers.format_number(num_obj, phonenumbers.PhoneNumberFormat.E164), tel_limpio
        except Exception:
            pass

    # Extractor de emergencia para dígitos puros
    digitos = re.sub(r'[^\d]', '', tel_limpio)
    # Estados Unidos (10 dígitos locales o 11 con prefijo 1)
    if codigo_region == 'US':
        if len(digitos) == 10:
            return f"+1{digitos}", tel_limpio
        if len(digitos) == 11 and digitos.startswith('1'):
            return f"+{digitos}", tel_limpio

    # Nicaragua (8 dígitos)
    if codigo_region == 'NI':
        if len(digitos) == 8:
            return f"+505{digitos}", tel_limpio
        if len(digitos) == 11 and digitos.startswith('505'):
            return f"+{digitos}", tel_limpio

    # Genérico si tiene entre 8 y 15 dígitos
    if 8 <= len(digitos) <= 15:
        return f"+{digitos}", tel_limpio

    return None, tel_limpio

def limpiar_texto(texto: Optional[str]) -> Optional[str]:
    """Elimina espacios repetidos y saltos de línea excesivos."""
    if not texto:
        return None
    return re.sub(r'\s+', ' ', texto).strip()
