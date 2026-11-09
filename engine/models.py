from typing import Optional
from pydantic import BaseModel, Field

class LeadExtracted(BaseModel):
    """Esquema universal de extracción para cualquier sector y país."""
    nombre_empresa: str = Field(
        description="Nombre comercial de la empresa, contratista, negocio o persona que ofrece el servicio"
    )
    tipo_entidad: Optional[str] = Field(
        default='Empresa / Negocio',
        description="Tipo de entidad: Empresa, Contratista, Negocio local, Proveedor, Prestamista, etc."
    )
    telefono: Optional[str] = Field(
        default=None,
        description="Número de teléfono de contacto, atención o celular con su código de área"
    )
    email: Optional[str] = Field(
        default=None,
        description="Correo electrónico si aparece publicado"
    )
    persona_contacto: Optional[str] = Field(
        default=None,
        description="Nombre del dueño, director, contratista o persona de contacto si aparece"
    )
    direccion: Optional[str] = Field(
        default=None,
        description="Dirección física, ciudad o estado"
    )
    detalles_oferta: Optional[str] = Field(
        default=None,
        description="Breve descripción de los servicios que prestan o condiciones comerciales"
    )
