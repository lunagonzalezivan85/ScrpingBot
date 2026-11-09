from typing import Optional, Literal
from pydantic import BaseModel, Field

class LeadExtracted(BaseModel):
    """Esquema de salida garantizado para el LLM."""
    nombre_empresa: str = Field(
        description="Nombre comercial de la microfinanciera, cooperativa o persona particular que ofrece préstamos o créditos"
    )
    tipo_entidad: Literal[
        'Microfinanciera / Empresa', 
        'Prestamista Particular', 
        'Cooperativa', 
        'Comercio / Negocio', 
        'No especificado'
    ] = Field(
        default='No especificado',
        description="Tipo de prestamista o institución identificada"
    )
    telefono: Optional[str] = Field(
        default=None,
        description="Número de teléfono de contacto o WhatsApp (debe contener los dígitos locales de Nicaragua)"
    )
    email: Optional[str] = Field(
        default=None,
        description="Correo electrónico si aparece publicado"
    )
    persona_contacto: Optional[str] = Field(
        default=None,
        description="Nombre de la persona, dueño, asesor o encargado"
    )
    direccion: Optional[str] = Field(
        default=None,
        description="Dirección física, barrio o referencia de ubicación en Chinandega o Nicaragua"
    )
    detalles_oferta: Optional[str] = Field(
        default=None,
        description="Breve resumen de las condiciones: requisitos, tipo de garantía, montos o plazos mencionados"
    )

