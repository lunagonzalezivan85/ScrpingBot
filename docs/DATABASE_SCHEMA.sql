-- =====================================================================
-- Base de Datos para el Sistema Scraph (Prospección y CRM Telefónico)
-- Compatible con MySQL 5.7+ / MariaDB 10.4+ en XAMPP
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `prospectos_crm` 
DEFAULT CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `prospectos_crm`;

-- ---------------------------------------------------------------------
-- 1. Tabla de Trabajos de Scraping (scraping_jobs)
-- Registra cada orden de prospección disparada por el usuario
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scraping_jobs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uuid` VARCHAR(36) NOT NULL UNIQUE,
    `sector` VARCHAR(100) NOT NULL,
    `ciudad` VARCHAR(100) NOT NULL,
    `pais` VARCHAR(50) DEFAULT 'Nicaragua',
    `fuente_tipo` ENUM('web_search', 'pdf_conami', 'directorio') DEFAULT 'web_search',
    `limite_solicitado` INT DEFAULT 10,
    `leads_encontrados` INT DEFAULT 0,
    `leads_guardados` INT DEFAULT 0,
    `status` ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    `error_mensaje` TEXT NULL,
    `iniciado_en` DATETIME NULL,
    `finalizado_en` DATETIME NULL,
    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_sector_ciudad` (`sector`, `ciudad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Tabla de Prospectos / Leads Comerciales (leads)
-- Almacena las empresas y personas encontradas con sus teléfonos
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT NULL,
    `sector` VARCHAR(100) NOT NULL,
    `ciudad` VARCHAR(100) NOT NULL,
    `departamento` VARCHAR(100) DEFAULT 'Chinandega',
    `pais` VARCHAR(50) DEFAULT 'Nicaragua',
    `nombre_empresa` VARCHAR(255) NOT NULL,
    `tipo_entidad` VARCHAR(100) DEFAULT 'Empresa',
    `telefono` VARCHAR(30) NOT NULL,
    `telefono_raw` VARCHAR(50) NULL,
    `email` VARCHAR(150) NULL,
    `persona_contacto` VARCHAR(150) NULL,
    `direccion` TEXT NULL,
    `sitio_web` VARCHAR(500) NULL,
    `detalles_oferta` TEXT NULL COMMENT 'Tipo de crédito, requisitos, montos o garantías',
    `estado_llamada` ENUM(
        'Pendiente',
        'Contactado - Interesado',
        'Contactado - No Interesado',
        'No contesta',
        'Volver a llamar',
        'Numero equivocado',
        'Descartado'
    ) DEFAULT 'Pendiente',
    `intentos_llamada` INT DEFAULT 0,
    `ultima_llamada_en` DATETIME NULL,
    `proxima_llamada_en` DATETIME NULL,
    `notas` TEXT NULL,
    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Restricción para evitar registrar el mismo número de teléfono dos veces
    UNIQUE KEY `uk_telefono` (`telefono`),
    INDEX `idx_estado_llamada` (`estado_llamada`),
    INDEX `idx_ciudad_sector` (`ciudad`, `sector`),
    INDEX `idx_tipo_entidad` (`tipo_entidad`),
    CONSTRAINT `fk_leads_job` FOREIGN KEY (`job_id`) REFERENCES `scraping_jobs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Tabla de Logs de Ejecución (job_logs)
-- Registra eventos, advertencias y pasos detallados de cada job
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `job_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT NOT NULL,
    `nivel` ENUM('info', 'warning', 'error', 'success') DEFAULT 'info',
    `mensaje` TEXT NOT NULL,
    `datos_contexto` JSON NULL,
    `creado_en` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_job_nivel` (`job_id`, `nivel`),
    CONSTRAINT `fk_logs_job` FOREIGN KEY (`job_id`) REFERENCES `scraping_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Datos iniciales de prueba (Opcional)
-- ---------------------------------------------------------------------
INSERT INTO `leads` 
(`sector`, `ciudad`, `departamento`, `nombre_empresa`, `tipo_entidad`, `telefono`, `direccion`, `detalles_oferta`, `estado_llamada`)
VALUES 
('Microcréditos', 'Chinandega', 'Chinandega', 'Microcréditos del Occidente S.A.', 'Microfinanciera / Empresa', '+50523411234', 'Costado sur de la Parroquia Santa Ana, Chinandega', 'Préstamos para pequeños comercios y agricultura', 'Pendiente'),
('Préstamos personales', 'Chinandega', 'Chinandega', 'Préstamos Rápidos El Viejo', 'Prestamista Particular', '+50588776655', 'Barrio El Rosario, El Viejo, Chinandega', 'Créditos diarios y semanales sin fiador', 'Pendiente')
ON DUPLICATE KEY UPDATE `nombre_empresa` = VALUES(`nombre_empresa`);

