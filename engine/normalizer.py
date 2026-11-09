import re
from typing import Optional

def normalizar_telefono_nicaragua(tel_raw: Optional[str]) -> tuple[Optional[str], Optional[str]]:
    """
    Limpia y valida un número telefónico de Nicaragua.
    Retorna una tupla: (telefono_normalizado_e164, telefono_crudo_limpio).
    
    Reglas de Nicaragua:
    - 8 dígitos locales.
    - Celulares empiezan con 8, 7 o 5 (Tigo / Claro).
    - Fijos empiezan con 2 (Chinandega típicamente 2341, 2342...).
    - Prefijo de país: +505.
    """
    if not tel_raw:
        return None, None

    # Limpiar espacios, guiones, paréntesis y puntos
    tel_limpio = tel_raw.strip()
    digitos = re.sub(r'[^\d]', '', tel_limpio)

    # Si empieza con 505 y tiene 11 dígitos
    if digitos.startswith('505') and len(digitos) == 11:
        digitos = digitos[3:]

    # Si tiene 8 dígitos válidos
    if len(digitos) == 8:
        # Validación de primer dígito en Nicaragua (2 para fijos, 5/7/8 para móviles)
        if digitos[0] in ('2', '5', '7', '8'):
            return f"+505{digitos}", tel_limpio

    # Si no cumple el formato estricto de Nicaragua
    return None, tel_limpio

def limpiar_texto(texto: Optional[str]) -> Optional[str]:
    """Elimina espacios repetidos y saltos de línea excesivos."""
    if not texto:
        return None
    return re.sub(r'\s+', ' ', texto).strip()

