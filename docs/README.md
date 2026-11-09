# Scraph - Sistema Inteligente de Prospección y CRM Telefónico B2B

Bienvenido a la documentación técnica y funcional de **Scraph**. Este sistema automatiza la búsqueda, extracción mediante Inteligencia Artificial (ScrapeGraphAI), almacenamiento y gestión de llamadas (Dial CRM) de empresas y particulares (microcréditos, prestamistas, comercios, etc.) enfocados inicialmente en **Chinandega y Nicaragua**, con capacidad de escalabilidad multirregión.

---

## 📚 Índice de Documentación

1. **[Arquitectura del Sistema (ARCHITECTURE.md)](file:///c:/xampp/htdocs/Scraph/docs/ARCHITECTURE.md)**
   - Desglose de los 3 niveles: Motor Python (IA/Scraping), Backend de Control (CodeIgniter 4 / PHP) y Frontend (React / Dashboard).
   - Diagrama de componentes y modelo de datos.

2. **[Flujo de Trabajo y Ciclo de Vida (WORKFLOW.md)](file:///c:/xampp/htdocs/Scraph/docs/WORKFLOW.md)**
   - Flujo integral desde la solicitud del usuario hasta la llamada telefónica/WhatsApp.
   - Algoritmo de filtrado de dominios y normalización telefónica (+505).

3. **[Esquema de Base de Datos (DATABASE_SCHEMA.sql)](file:///c:/xampp/htdocs/Scraph/docs/DATABASE_SCHEMA.sql)**
   - Script SQL listo para importar en MySQL (XAMPP / phpMyAdmin).
   - Tablas: `leads`, `scraping_jobs`, `job_logs`.

4. **[Integración y Contrato de API (API_AND_INTEGRATION.md)](file:///c:/xampp/htdocs/Scraph/docs/API_AND_INTEGRATION.md)**
   - Métodos de comunicación entre CodeIgniter y el Motor Python (CLI Worker vs Microservicio FastAPI).
   - Formatos JSON de entrada/salida y manejo de errores.

---

## 🛠️ Tecnologías Utilizadas

- **Motor de Extracción:** Python 3.10+, [ScrapeGraphAI](https://github.com/ScrapeGraphAI/Scrapegraph-ai), Playwright, DuckDuckGo Search, Pydantic.
- **LLMs Soportados:** Google Gemini Flash (Recomendado por coste/velocidad), OpenAI GPT-4o-mini o modelos locales vía Ollama.
- **Backend & Control:** PHP 8.1+, CodeIgniter 4 (MVC, controladores REST, validaciones, gestión de sesiones).
- **Frontend:** React 18+ con TailwindCSS / Bootstrap (gestión reactiva de prospectos, marcado rápido, visualización en tiempo real).
- **Base de Datos:** MySQL / MariaDB (integrado en XAMPP).
- **Servidor Web:** Apache (XAMPP).

---

## 🚀 Guía Rápida de Puesta en Marcha

### Prerrequisitos
- XAMPP con Apache y MySQL corriendo en `localhost:80` y `localhost:3306`.
- Python 3.10 o superior instalado con `pip` y agregado al `PATH`.
- Node.js 18+ (para el desarrollo/compilación de React).

### Pasos de inicialización
1. **Base de Datos:** Importar el archivo `docs/DATABASE_SCHEMA.sql` en phpMyAdmin (`http://localhost/phpmyadmin`).
2. **Motor Python:** Crear entorno virtual y descargar dependencias:
   ```bash
   cd c:\xampp\htdocs\Scraph\engine
   python -m venv venv
   .\venv\Scripts\activate
   pip install -r requirements.txt
   playwright install chromium
   ```
3. **Backend CodeIgniter:** Configurar `.env` con credenciales de base de datos.
4. **Frontend React:** Compilar los assets o correr en modo desarrollo con Vite.

