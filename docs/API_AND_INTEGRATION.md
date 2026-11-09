# Integración y Especificación de APIs - Scraph

Este documento describe la interfaz de comunicación entre el frontend en React, el servidor de control en **CodeIgniter 4**, y el motor de extracción en **Python**.

---

## 1. Patrón de Integración (CodeIgniter <-> Python Engine)

Para orquestar el scraping sin saturar el servidor ni bloquear las peticiones del usuario, se implementa el patrón **Job & Worker Asíncrono**:

```
[React] ──POST /api/jobs──> [CodeIgniter 4]
                                 │
                   (1) Inserta Job en MySQL (pending)
                   (2) Lanza proceso detached en Windows
                                 │
                                 ▼
                     [python engine/worker.py --job_id=42]
                                 │
           (Lee parámetros del job, extrae con IA y escribe en MySQL)
```

### Comando de ejecución en Windows (desde CodeIgniter):
```php
// En CodeIgniter: App\Libraries\PythonRunner.php
$comando = sprintf(
    'start /B "" "c:\xampp\htdocs\Scraph\engine\venv\Scripts\python.exe" "c:\xampp\htdocs\Scraph\engine\worker.py" --job_id=%d > "c:\xampp\htdocs\Scraph\writable\logs\job_%d.log" 2>&1',
    $jobId,
    $jobId
);
pclose(popen($comando, "r"));
```

---

## 2. Especificación de Endpoints REST (CodeIgniter 4)

Todos los endpoints responden en formato `application/json`.

### 2.1. Gestión de Trabajos de Scraping

#### `POST /api/jobs`
Crea e inicia una nueva campaña de prospección.

- **Request Body:**
```json
{
  "sector": "Microcréditos y préstamos",
  "ciudad": "Chinandega",
  "pais": "Nicaragua",
  "fuente_tipo": "web_search",
  "limite": 10
}
```

- **Response (201 Created):**
```json
{
  "status": "success",
  "message": "Trabajo de prospección encolado e iniciado.",
  "data": {
    "job_id": 14,
    "uuid": "e8b2a3d0-6f29-4d6b-9c60-845a7d65f3a1",
    "status": "running",
    "created_at": "2026-11-09 07:40:00"
  }
}
```

---

#### `GET /api/jobs/{id}/status`
Consulta el estado de avance en tiempo real (utilizado por el polling de React).

- **Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "job_id": 14,
    "status": "running",
    "limite_solicitado": 10,
    "leads_guardados": 4,
    "logs": [
      {"nivel": "info", "mensaje": "Descubiertas 8 URLs candidatas"},
      {"nivel": "success", "mensaje": "Guardado: Financiera FAMA Chinandega -> +50523412000"}
    ]
  }
}
```

---

### 2.2. Gestión de Prospectos (Dial CRM)

#### `GET /api/leads`
Lista prospectos con filtros y paginación.

- **Query Parameters:**
  - `page`: Número de página (default: 1).
  - `limit`: Cantidad por página (default: 20).
  - `estado`: Filtrar por estado (`Pendiente`, `Contactado - Interesado`, etc.).
  - `ciudad`: Filtrar por ciudad (ej. `Chinandega`).
  - `buscar`: Búsqueda textual por nombre o teléfono.

- **Response (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "id": 102,
      "nombre_empresa": "Préstamos Rápidos El Viejo",
      "tipo_entidad": "Prestamista Particular",
      "telefono": "+50588776655",
      "ciudad": "Chinandega",
      "direccion": "Barrio El Rosario, El Viejo",
      "detalles_oferta": "Préstamos diarios para comerciantes sin fiador",
      "estado_llamada": "Pendiente",
      "intentos_llamada": 0,
      "notas": null,
      "creado_en": "2026-11-09 07:42:10"
    }
  ],
  "pagination": {
    "current_page": 1,
    "total_pages": 4,
    "total_records": 78
  }
}
```

---

#### `PATCH /api/leads/{id}`
Actualiza el resultado de la llamada realizada por el operador.

- **Request Body:**
```json
{
  "estado_llamada": "Contactado - Interesado",
  "notas": "Hablé con Don Carlos. Le interesa financiamiento para su ferretería. Volver a llamar el viernes a las 10 AM.",
  "proxima_llamada_en": "2026-11-14 10:00:00"
}
```

- **Response (200 OK):**
```json
{
  "status": "success",
  "message": "Lead actualizado correctamente.",
  "data": {
    "id": 102,
    "estado_llamada": "Contactado - Interesado",
    "intentos_llamada": 1,
    "ultima_llamada_en": "2026-11-09 07:45:22"
  }
}
```

---

## 3. Formato del Contrato de Datos para el Motor Python

Cuando el worker de Python ejecuta un trabajo, lee la configuración desde MySQL y procesa la información asegurando este esquema JSON antes de escribir en la tabla `leads`:

```json
{
  "nombre_empresa": "Cadena de texto requerida",
  "tipo_entidad": "Microfinanciera / Empresa | Prestamista Particular | Cooperativa | No especificado",
  "telefono": "+505XXXXXXXX (E.164 obligatorio)",
  "telefono_raw": "Texto original extraído del HTML",
  "email": "correo@ejemplo.com o null",
  "persona_contacto": "Nombre o null",
  "direccion": "Texto o null",
  "sitio_web": "URL origen",
  "detalles_oferta": "Resumen de condiciones"
}
```

