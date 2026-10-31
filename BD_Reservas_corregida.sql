-- =====================================================================
-- reservas_cuv_2  |  Script corregido (MySQL 8+, compatible con Laravel)
-- Cambios principales respecto al original:
--   * Se crea la BD y se corrige el "USE" (faltaba el punto y coma)
--   * Script repetible (DROP TABLE IF EXISTS)
--   * Llaves BIGINT UNSIGNED (igual que foreignId() de Laravel)
--   * Collation unificada utf8mb4_unicode_ci
--   * UNIQUE en pivotes y catalogos para evitar duplicados
--   * CHECK en rangos (horas, limites, cantidades)
--   * ON DELETE SET NULL en FKs opcionales
--   * FK compuesta en reservas -> asignaturas (coherencia licenciatura/cuatrimestre)
--   * Estado 'cancelada' agregado a estados_reserva
--   * remember_token en usuarios (autenticacion Laravel)
--   * Indices para consultas frecuentes y para evitar choques de horario
-- =====================================================================

CREATE DATABASE IF NOT EXISTS reservas_cuv_2
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE reservas_cuv_2;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notificaciones;
DROP TABLE IF EXISTS anuncios;
DROP TABLE IF EXISTS historial_reservas;
DROP TABLE IF EXISTS reserva_detalle_requerimientos;
DROP TABLE IF EXISTS reserva_detalles;
DROP TABLE IF EXISTS reservas;
DROP TABLE IF EXISTS estados_reserva;
DROP TABLE IF EXISTS asignaturas;
DROP TABLE IF EXISTS cuatrimestres;
DROP TABLE IF EXISTS actividades;
DROP TABLE IF EXISTS espacio_requerimientos;
DROP TABLE IF EXISTS requerimientos;
DROP TABLE IF EXISTS espacios;
DROP TABLE IF EXISTS solicitudes_cambio_password;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS usuario_licenciaturas;
DROP TABLE IF EXISTS licenciaturas;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS roles;

-- ---------------------------------------------------------------------
-- ROLES
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL,
  nivel TINYINT UNSIGNED NOT NULL,
  descripcion VARCHAR(150) NULL,
  UNIQUE KEY uq_roles_nombre (nombre),
  UNIQUE KEY uq_roles_nivel (nivel),
  CONSTRAINT chk_roles_nivel CHECK (nivel BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (nombre, nivel, descripcion) VALUES
  ('superadmin',  100, 'Control total del sistema'),
  ('admin',        90, 'Autoriza o deniega solicitudes y administra catálogos y usuarios'),
  ('coordinador',  70, 'Revisa solicitudes de los docentes de sus licenciaturas'),
  ('docente',      50, 'Llena formatos y los envía a su coordinador');

-- ---------------------------------------------------------------------
-- USUARIOS
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  apellido_paterno VARCHAR(100) NOT NULL,
  apellido_materno VARCHAR(100) NULL,
  correo VARCHAR(150) NOT NULL,
  telefono VARCHAR(20) NULL,
  password VARCHAR(255) NOT NULL,
  remember_token VARCHAR(100) NULL,
  rol_id BIGINT UNSIGNED NOT NULL,
  estatus ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 1,
  fecha_registro TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuarios_correo (correo),
  KEY idx_usuarios_rol (rol_id),
  CONSTRAINT fk_usuario_rol FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- LICENCIATURAS
-- ---------------------------------------------------------------------
CREATE TABLE licenciaturas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  estatus ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
  UNIQUE KEY uq_licenciaturas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- N:M usuarios <-> licenciaturas (coordinadores y docentes)
CREATE TABLE usuario_licenciaturas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NOT NULL,
  licenciatura_id BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_usuario_licenciatura (usuario_id, licenciatura_id),
  KEY idx_ul_licenciatura (licenciatura_id),
  CONSTRAINT fk_ul_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_ul_licenciatura FOREIGN KEY (licenciatura_id) REFERENCES licenciaturas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PASSWORD
-- Nota: Laravel trae su propia migracion de password_reset_tokens.
-- Usa esta tabla O la de Laravel, no ambas. El token se guarda hasheado.
-- ---------------------------------------------------------------------
CREATE TABLE password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NOT NULL,
  token VARCHAR(255) NOT NULL,
  expiracion DATETIME NOT NULL,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pr_usuario (usuario_id),
  KEY idx_pr_token (token),
  CONSTRAINT fk_pr_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE solicitudes_cambio_password (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NOT NULL,
  motivo TEXT NOT NULL,
  estado ENUM('pendiente','autorizada','rechazada') NOT NULL DEFAULT 'pendiente',
  respuesta_admin TEXT NULL,
  fecha_solicitud TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_respuesta DATETIME NULL,
  KEY idx_scp_usuario_estado (usuario_id, estado),
  CONSTRAINT fk_scp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ESPACIOS Y REQUERIMIENTOS
-- ---------------------------------------------------------------------
CREATE TABLE espacios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT NULL,
  ubicacion VARCHAR(150) NULL,
  foto VARCHAR(255) NULL,
  capacidad INT UNSIGNED NULL,
  minimo_alumnos INT UNSIGNED NULL,
  maximo_alumnos INT UNSIGNED NULL,
  color_calendario VARCHAR(20) NOT NULL DEFAULT '#dc2626',
  estatus ENUM('disponible','mantenimiento','inactivo') NOT NULL DEFAULT 'disponible',
  fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_espacios_nombre (nombre),
  CONSTRAINT chk_espacios_alumnos CHECK (minimo_alumnos IS NULL OR maximo_alumnos IS NULL OR minimo_alumnos <= maximo_alumnos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE requerimientos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  estatus ENUM('activo','eliminado') NOT NULL DEFAULT 'activo',
  fecha_registro TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_requerimientos_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE espacio_requerimientos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  espacio_id BIGINT UNSIGNED NOT NULL,
  requerimiento_id BIGINT UNSIGNED NOT NULL,
  cantidad_disponible INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_espacio_requerimiento (espacio_id, requerimiento_id),
  KEY idx_er_requerimiento (requerimiento_id),
  CONSTRAINT fk_er_espacio FOREIGN KEY (espacio_id) REFERENCES espacios(id) ON DELETE CASCADE,
  CONSTRAINT fk_er_requerimiento FOREIGN KEY (requerimiento_id) REFERENCES requerimientos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ACTIVIDADES, CUATRIMESTRES, ASIGNATURAS
-- ---------------------------------------------------------------------
CREATE TABLE actividades (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  estatus ENUM('activa','eliminada') NOT NULL DEFAULT 'activa',
  fecha_registro TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_actividades_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cuatrimestres (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero TINYINT UNSIGNED NOT NULL,
  nombre VARCHAR(50) NOT NULL,
  UNIQUE KEY uq_cuatrimestres_numero (numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cuatrimestres (numero, nombre) VALUES
  (1,'Primer cuatrimestre'), (2,'Segundo cuatrimestre'), (3,'Tercer cuatrimestre'),
  (4,'Cuarto cuatrimestre'), (5,'Quinto cuatrimestre'), (6,'Sexto cuatrimestre'),
  (7,'Séptimo cuatrimestre'), (8,'Octavo cuatrimestre'), (9,'Noveno cuatrimestre');

CREATE TABLE asignaturas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  licenciatura_id BIGINT UNSIGNED NOT NULL,
  cuatrimestre_id BIGINT UNSIGNED NOT NULL,
  estatus ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
  UNIQUE KEY uq_asignatura (nombre, licenciatura_id, cuatrimestre_id),
  -- Necesario para la FK compuesta desde reservas
  UNIQUE KEY uq_asignatura_lic_cuat (id, licenciatura_id, cuatrimestre_id),
  KEY idx_asig_licenciatura (licenciatura_id),
  KEY idx_asig_cuatrimestre (cuatrimestre_id),
  CONSTRAINT fk_asig_licenciatura FOREIGN KEY (licenciatura_id) REFERENCES licenciaturas(id),
  CONSTRAINT fk_asig_cuatrimestre FOREIGN KEY (cuatrimestre_id) REFERENCES cuatrimestres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ESTADOS DE RESERVA
-- ---------------------------------------------------------------------
CREATE TABLE estados_reserva (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL,
  descripcion VARCHAR(150) NULL,
  orden TINYINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_estados_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO estados_reserva (id, nombre, descripcion, orden) VALUES
  (1, 'borrador',              'El creador aún la está llenando',                 1),
  (2, 'pendiente_coordinador', 'Enviada por docente, espera revisión',            2),
  (3, 'correccion',            'El coordinador o el admin pidió ajustes',         3),
  (4, 'pendiente_admin',       'Aprobada por coordinador, espera al admin',       4),
  (5, 'autorizada',            'Autorizada por el admin',                         5),
  (6, 'denegada',              'Rechazada por el admin',                          6),
  (7, 'cancelada',             'Cancelada por el creador o el administrador',     7);

-- ---------------------------------------------------------------------
-- RESERVAS
-- ---------------------------------------------------------------------
CREATE TABLE reservas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_creador_id BIGINT UNSIGNED NOT NULL,   -- docente o coordinador que llena el formato
  coordinador_id BIGINT UNSIGNED NULL,           -- coordinador que debe revisar
  actividad_id BIGINT UNSIGNED NOT NULL,
  licenciatura_id BIGINT UNSIGNED NOT NULL,
  tipo_formato ENUM('normal','ext') NOT NULL DEFAULT 'normal',
  nombre_actividad VARCHAR(255) NULL,
  folio VARCHAR(100) NULL,
  objetivo TEXT NOT NULL,
  cuatrimestre_id BIGINT UNSIGNED NULL,
  asignatura_id BIGINT UNSIGNED NULL,
  costo_alumno DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  promocion_redes TINYINT(1) NOT NULL DEFAULT 0,  -- antes ENUM('si','no'); ahora booleano
  limite_min_usuarios INT UNSIGNED NULL,
  limite_max_usuarios INT UNSIGNED NULL,
  fecha_entrega_formato DATE NULL,
  estado_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  observaciones_admin TEXT NULL,
  fecha_creacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_actualizacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reservas_folio (folio),
  KEY idx_reservas_creador (usuario_creador_id),
  KEY idx_reservas_coord_estado (coordinador_id, estado_id),
  KEY idx_reservas_estado (estado_id),
  KEY idx_reservas_actividad (actividad_id),
  KEY idx_reservas_licenciatura (licenciatura_id),
  KEY idx_reservas_cuatrimestre (cuatrimestre_id),
  CONSTRAINT chk_reservas_limites CHECK (limite_min_usuarios IS NULL OR limite_max_usuarios IS NULL OR limite_min_usuarios <= limite_max_usuarios),
  CONSTRAINT chk_reservas_costo CHECK (costo_alumno >= 0),
  CONSTRAINT fk_reserva_creador FOREIGN KEY (usuario_creador_id) REFERENCES usuarios(id),
  CONSTRAINT fk_reserva_coordinador FOREIGN KEY (coordinador_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_reserva_actividad FOREIGN KEY (actividad_id) REFERENCES actividades(id),
  CONSTRAINT fk_reserva_licenciatura FOREIGN KEY (licenciatura_id) REFERENCES licenciaturas(id),
  CONSTRAINT fk_reserva_cuatrimestre FOREIGN KEY (cuatrimestre_id) REFERENCES cuatrimestres(id),
  -- FK compuesta: la asignatura debe pertenecer a la licenciatura y cuatrimestre indicados
  -- (si asignatura_id o cuatrimestre_id es NULL, MySQL no evalua la restriccion)
  CONSTRAINT fk_reserva_asignatura FOREIGN KEY (asignatura_id, licenciatura_id, cuatrimestre_id)
    REFERENCES asignaturas(id, licenciatura_id, cuatrimestre_id),
  CONSTRAINT fk_reserva_estado FOREIGN KEY (estado_id) REFERENCES estados_reserva(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reserva_detalles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reserva_id BIGINT UNSIGNED NOT NULL,
  espacio_id BIGINT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  docente_responsable_id BIGINT UNSIGNED NULL,
  cantidad_alumnos INT UNSIGNED NULL,
  lugar_especifico VARCHAR(150) NULL,
  descripcion_actividad TEXT NULL,
  material_requerido TEXT NULL,
  observaciones TEXT NULL,
  estado_detalle ENUM('pendiente','autorizada','denegada','cancelada') NOT NULL DEFAULT 'pendiente',
  KEY idx_detalle_reserva (reserva_id),
  KEY idx_detalle_espacio_horario (espacio_id, fecha, hora_inicio, hora_fin),
  KEY idx_detalle_fecha (fecha),
  KEY idx_detalle_docente (docente_responsable_id),
  CONSTRAINT chk_detalle_horas CHECK (hora_fin > hora_inicio),
  CONSTRAINT fk_detalle_reserva FOREIGN KEY (reserva_id) REFERENCES reservas(id) ON DELETE CASCADE,
  CONSTRAINT fk_detalle_espacio FOREIGN KEY (espacio_id) REFERENCES espacios(id),
  CONSTRAINT fk_detalle_docente FOREIGN KEY (docente_responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reserva_detalle_requerimientos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reserva_detalle_id BIGINT UNSIGNED NOT NULL,
  requerimiento_id BIGINT UNSIGNED NOT NULL,
  cantidad INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_detalle_requerimiento (reserva_detalle_id, requerimiento_id),
  KEY idx_rdr_requerimiento (requerimiento_id),
  CONSTRAINT chk_rdr_cantidad CHECK (cantidad > 0),
  CONSTRAINT fk_rdr_detalle FOREIGN KEY (reserva_detalle_id) REFERENCES reserva_detalles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rdr_requerimiento FOREIGN KEY (requerimiento_id) REFERENCES requerimientos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE historial_reservas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reserva_id BIGINT UNSIGNED NOT NULL,
  usuario_id BIGINT UNSIGNED NOT NULL,
  accion ENUM('creada','enviada_coordinador','correccion_solicitada',
              'aprobada_coordinador','enviada_admin',
              'autorizada','denegada','cancelada') NOT NULL,
  comentario TEXT NULL,
  fecha TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_historial_reserva_fecha (reserva_id, fecha),
  KEY idx_historial_usuario (usuario_id),
  CONSTRAINT fk_historial_reserva FOREIGN KEY (reserva_id) REFERENCES reservas(id) ON DELETE CASCADE,
  CONSTRAINT fk_historial_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ANUNCIOS Y NOTIFICACIONES
-- ---------------------------------------------------------------------
CREATE TABLE anuncios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id BIGINT UNSIGNED NOT NULL,
  titulo VARCHAR(200) NOT NULL,
  contenido TEXT NOT NULL,
  imagen VARCHAR(255) NULL,
  fecha_publicacion TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  estatus ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  KEY idx_anuncios_admin (admin_id),
  KEY idx_anuncios_estatus_fecha (estatus, fecha_publicacion),
  CONSTRAINT fk_anuncio_admin FOREIGN KEY (admin_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notificaciones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NOT NULL,
  titulo VARCHAR(180) NOT NULL,
  mensaje TEXT NOT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'info',
  leida TINYINT(1) NOT NULL DEFAULT 0,
  fecha TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_usuario_leida (usuario_id, leida),
  CONSTRAINT fk_notificacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- VERIFICACION RAPIDA (ejecutar despues de crear)
-- =====================================================================
-- SHOW TABLES;   -- deben ser 19 tablas
-- SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
--   FROM information_schema.KEY_COLUMN_USAGE
--  WHERE TABLE_SCHEMA='reservas_cuv_2' AND REFERENCED_TABLE_NAME IS NOT NULL;
