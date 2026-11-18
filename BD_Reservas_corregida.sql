-- =====================================================================
-- reservas_cuv_2  |  Script de la base de datos (MySQL 8+)
-- Generado el 2026-11-18 a partir de las migraciones de Laravel
-- (database/migrations), con la misma estructura que crea
-- `php artisan migrate`. Incluye los catálogos base que insertan las
-- migraciones (roles, estados de reserva y cuatrimestres) y ninguna
-- reserva ni usuario.
--
-- Uso:  mysql -u root -p < BD_Reservas_corregida.sql
-- Después, para crear el superadministrador, define ADMIN_CORREO y
-- ADMIN_PASSWORD en el .env y ejecuta:  php artisan db:seed --class=AdminSeeder
-- (o php artisan db:seed --class=RolSeeder ... para catálogos de ejemplo).
--
-- Script repetible: borra las tablas existentes antes de crearlas.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS reservas_cuv_2
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE reservas_cuv_2;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `migrations`;
DROP TABLE IF EXISTS `reserva_detalle_requerimientos`;
DROP TABLE IF EXISTS `espacio_requerimientos`;
DROP TABLE IF EXISTS `requerimientos`;
DROP TABLE IF EXISTS `passkeys`;
DROP TABLE IF EXISTS `notificaciones`;
DROP TABLE IF EXISTS `historial_reservas`;
DROP TABLE IF EXISTS `reserva_detalles`;
DROP TABLE IF EXISTS `reservas`;
DROP TABLE IF EXISTS `espacios`;
DROP TABLE IF EXISTS `asignaturas`;
DROP TABLE IF EXISTS `cuatrimestres`;
DROP TABLE IF EXISTS `actividades`;
DROP TABLE IF EXISTS `estados_reserva`;
DROP TABLE IF EXISTS `usuario_licenciaturas`;
DROP TABLE IF EXISTS `licenciaturas`;
DROP TABLE IF EXISTS `usuarios`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `job_batches`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `cache_locks`;
DROP TABLE IF EXISTS `cache`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `password_reset_tokens`;

-- ---------------------------------------------------------------------
-- 0001_01_01_000000_create_users_table
-- ---------------------------------------------------------------------
create table `password_reset_tokens` (`email` varchar(255) not null, `token` varchar(255) not null, `created_at` timestamp null, primary key (`email`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
create table `sessions` (`id` varchar(255) not null, `user_id` bigint unsigned null, `ip_address` varchar(45) null, `user_agent` text null, `payload` longtext not null, `last_activity` int not null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `sessions` add index `sessions_user_id_index`(`user_id`);
alter table `sessions` add index `sessions_last_activity_index`(`last_activity`);

-- ---------------------------------------------------------------------
-- 0001_01_01_000001_create_cache_table
-- ---------------------------------------------------------------------
create table `cache` (`key` varchar(255) not null, `value` mediumtext not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `cache` add index `cache_expiration_index`(`expiration`);
create table `cache_locks` (`key` varchar(255) not null, `owner` varchar(255) not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `cache_locks` add index `cache_locks_expiration_index`(`expiration`);

-- ---------------------------------------------------------------------
-- 0001_01_01_000002_create_jobs_table
-- ---------------------------------------------------------------------
create table `jobs` (`id` bigint unsigned not null auto_increment primary key, `queue` varchar(255) not null, `payload` longtext not null, `attempts` smallint unsigned not null, `reserved_at` int unsigned null, `available_at` int unsigned not null, `created_at` int unsigned not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `jobs` add index `jobs_queue_index`(`queue`);
create table `job_batches` (`id` varchar(255) not null, `name` varchar(255) not null, `total_jobs` int not null, `pending_jobs` int not null, `failed_jobs` int not null, `failed_job_ids` longtext not null, `options` mediumtext null, `cancelled_at` int null, `created_at` int not null, `finished_at` int null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
create table `failed_jobs` (`id` bigint unsigned not null auto_increment primary key, `uuid` varchar(255) not null, `connection` varchar(255) not null, `queue` varchar(255) not null, `payload` longtext not null, `exception` longtext not null, `failed_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `failed_jobs` add index `failed_jobs_connection_queue_failed_at_index`(`connection`, `queue`, `failed_at`);
alter table `failed_jobs` add unique `failed_jobs_uuid_unique`(`uuid`);

-- ---------------------------------------------------------------------
-- 0001_01_01_000003_create_roles_and_usuarios_tables
-- ---------------------------------------------------------------------
create table `roles` (`id` int not null auto_increment primary key, `nombre` varchar(50) not null, `nivel` tinyint unsigned not null, `descripcion` varchar(150) null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `roles` add unique `roles_nombre_unique`(`nombre`);
alter table `roles` add unique `roles_nivel_unique`(`nivel`);
insert into `roles` (`descripcion`, `nivel`, `nombre`) values ('Control total del sistema', 100, 'superadmin'), ('Autoriza o deniega solicitudes y administra catálogos y usuarios', 90, 'admin'), ('Revisa solicitudes de los docentes de sus licenciaturas', 70, 'coordinador'), ('Llena formatos y los envía a su coordinador', 50, 'docente');
create table `usuarios` (`id` int not null auto_increment primary key, `nombre` varchar(100) not null, `apellido_paterno` varchar(100) not null, `apellido_materno` varchar(100) null, `correo` varchar(150) not null, `telefono` varchar(20) null, `password` varchar(255) not null, `rol_id` int not null, `estatus` enum('activo', 'inactivo') null default 'activo', `debe_cambiar_password` tinyint(1) null default '1', `fecha_registro` timestamp null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `usuarios` add constraint `usuarios_rol_id_foreign` foreign key (`rol_id`) references `roles` (`id`);
alter table `usuarios` add unique `usuarios_correo_unique`(`correo`);
alter table `usuarios` add index `usuarios_rol_id_index`(`rol_id`);

-- ---------------------------------------------------------------------
-- 0001_01_01_000004_create_licenciaturas_and_estados_reserva_tables
-- ---------------------------------------------------------------------
create table `licenciaturas` (`id` int not null auto_increment primary key, `nombre` varchar(100) not null, `estatus` enum('activa', 'inactiva') null default 'activa') default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
create table `usuario_licenciaturas` (`id` int not null auto_increment primary key, `usuario_id` int not null, `licenciatura_id` int not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `usuario_licenciaturas` add unique `uq_usuario_licenciatura`(`usuario_id`, `licenciatura_id`);
alter table `usuario_licenciaturas` add constraint `usuario_licenciaturas_usuario_id_foreign` foreign key (`usuario_id`) references `usuarios` (`id`);
alter table `usuario_licenciaturas` add constraint `usuario_licenciaturas_licenciatura_id_foreign` foreign key (`licenciatura_id`) references `licenciaturas` (`id`);
create table `estados_reserva` (`id` int not null auto_increment primary key, `nombre` varchar(50) not null, `descripcion` varchar(150) null, `orden` tinyint unsigned not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `estados_reserva` add unique `estados_reserva_nombre_unique`(`nombre`);
insert into `estados_reserva` (`descripcion`, `id`, `nombre`, `orden`) values ('El creador aún la está llenando', 1, 'borrador', 1), ('Enviada por docente, espera revisión', 2, 'pendiente_coordinador', 2), ('El admin pidió ajustes', 3, 'correccion', 3), ('Aprobada por coordinador, espera admin', 4, 'pendiente_admin', 4), ('Autorizada por el admin', 5, 'autorizada', 5), ('Rechazada por admin', 6, 'denegada', 6);

-- ---------------------------------------------------------------------
-- 0001_01_01_000005_create_reservas_tables
-- ---------------------------------------------------------------------
create table `actividades` (`id` int not null auto_increment primary key, `nombre` varchar(150) not null, `estatus` enum('activa', 'eliminada') null default 'activa', `fecha_registro` timestamp null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
create table `cuatrimestres` (`id` int not null auto_increment primary key, `numero` tinyint unsigned not null, `nombre` varchar(50) not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `cuatrimestres` add unique `cuatrimestres_numero_unique`(`numero`);
insert into `cuatrimestres` (`id`, `nombre`, `numero`) values (1, 'Primer cuatrimestre', 1), (2, 'Segundo cuatrimestre', 2), (3, 'Tercer cuatrimestre', 3), (4, 'Cuarto cuatrimestre', 4), (5, 'Quinto cuatrimestre', 5), (6, 'Sexto cuatrimestre', 6), (7, 'Séptimo cuatrimestre', 7), (8, 'Octavo cuatrimestre', 8), (9, 'Noveno cuatrimestre', 9);
create table `asignaturas` (`id` int not null auto_increment primary key, `nombre` varchar(150) not null, `licenciatura_id` int not null, `cuatrimestre_id` int not null, `estatus` enum('activa', 'inactiva') null default 'activa') default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `asignaturas` add constraint `asignaturas_licenciatura_id_foreign` foreign key (`licenciatura_id`) references `licenciaturas` (`id`);
alter table `asignaturas` add constraint `asignaturas_cuatrimestre_id_foreign` foreign key (`cuatrimestre_id`) references `cuatrimestres` (`id`);
alter table `asignaturas` add index `asignaturas_nombre_index`(`nombre`);
create table `espacios` (`id` int not null auto_increment primary key, `nombre` varchar(100) not null, `descripcion` text null, `ubicacion` varchar(150) null, `foto` varchar(255) null, `capacidad` int null, `minimo_alumnos` int null, `maximo_alumnos` int null, `color_calendario` varchar(20) null default '#dc2626', `estatus` enum('disponible', 'mantenimiento', 'inactivo') null default 'disponible', `fecha_creacion` timestamp null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
create table `reservas` (`id` int not null auto_increment primary key, `usuario_creador_id` int not null, `coordinador_id` int null, `actividad_id` int not null, `licenciatura_id` int not null, `tipo_formato` enum('normal', 'ext') null default 'normal', `nombre_actividad` varchar(255) null, `folio` varchar(100) null, `objetivo` text not null, `cuatrimestre_id` int null, `asignatura_id` int null, `costo_alumno` decimal(10, 2) null default '0', `promocion_redes` enum('si', 'no') null default 'no', `limite_min_usuarios` int null, `limite_max_usuarios` int null, `fecha_entrega_formato` date null, `estado_id` int not null default '1', `observaciones_admin` text null, `fecha_creacion` timestamp null default CURRENT_TIMESTAMP, `fecha_actualizacion` timestamp null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `reservas` add constraint `reservas_usuario_creador_id_foreign` foreign key (`usuario_creador_id`) references `usuarios` (`id`);
alter table `reservas` add constraint `reservas_coordinador_id_foreign` foreign key (`coordinador_id`) references `usuarios` (`id`);
alter table `reservas` add constraint `reservas_actividad_id_foreign` foreign key (`actividad_id`) references `actividades` (`id`);
alter table `reservas` add constraint `reservas_licenciatura_id_foreign` foreign key (`licenciatura_id`) references `licenciaturas` (`id`);
alter table `reservas` add constraint `reservas_cuatrimestre_id_foreign` foreign key (`cuatrimestre_id`) references `cuatrimestres` (`id`);
alter table `reservas` add constraint `reservas_asignatura_id_foreign` foreign key (`asignatura_id`) references `asignaturas` (`id`);
alter table `reservas` add constraint `reservas_estado_id_foreign` foreign key (`estado_id`) references `estados_reserva` (`id`);
create table `reserva_detalles` (`id` int not null auto_increment primary key, `reserva_id` int not null, `espacio_id` int not null, `fecha` date not null, `hora_inicio` time not null, `hora_fin` time not null, `docente_responsable_id` int null, `cantidad_alumnos` int null, `lugar_especifico` varchar(150) null, `descripcion_actividad` text null, `material_requerido` text null, `observaciones` text null, `estado_detalle` enum('pendiente', 'autorizada', 'denegada', 'cancelada') null default 'pendiente') default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `reserva_detalles` add constraint `reserva_detalles_reserva_id_foreign` foreign key (`reserva_id`) references `reservas` (`id`) on delete cascade;
alter table `reserva_detalles` add constraint `reserva_detalles_espacio_id_foreign` foreign key (`espacio_id`) references `espacios` (`id`);
alter table `reserva_detalles` add constraint `reserva_detalles_docente_responsable_id_foreign` foreign key (`docente_responsable_id`) references `usuarios` (`id`);
create table `historial_reservas` (`id` int not null auto_increment primary key, `reserva_id` int not null, `usuario_id` int not null, `accion` enum('creada', 'enviada_coordinador', 'correccion_solicitada', 'aprobada_coordinador', 'enviada_admin', 'autorizada', 'denegada', 'cancelada') not null, `comentario` text null, `fecha` timestamp null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `historial_reservas` add constraint `historial_reservas_reserva_id_foreign` foreign key (`reserva_id`) references `reservas` (`id`) on delete cascade;
alter table `historial_reservas` add constraint `historial_reservas_usuario_id_foreign` foreign key (`usuario_id`) references `usuarios` (`id`);

-- ---------------------------------------------------------------------
-- 0001_01_01_000006_create_notificaciones_table
-- ---------------------------------------------------------------------
create table `notificaciones` (`id` int not null auto_increment primary key, `usuario_id` int not null, `titulo` varchar(180) not null, `mensaje` text not null, `tipo` varchar(30) null default 'info', `leida` tinyint(1) null default '0', `fecha` timestamp null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `notificaciones` add constraint `notificaciones_usuario_id_foreign` foreign key (`usuario_id`) references `usuarios` (`id`);

-- ---------------------------------------------------------------------
-- 2024_01_01_000000_create_passkeys_table
-- ---------------------------------------------------------------------
create table `passkeys` (`id` bigint unsigned not null auto_increment primary key, `user_id` int not null, `name` varchar(255) not null, `credential_id` varchar(255) not null, `credential` json not null, `last_used_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `passkeys` add index `passkeys_user_id_index`(`user_id`);
alter table `passkeys` add constraint `passkeys_user_id_foreign` foreign key (`user_id`) references `usuarios` (`id`) on delete cascade;
alter table `passkeys` add unique `passkeys_credential_id_unique`(`credential_id`);

-- ---------------------------------------------------------------------
-- 2026_11_08_000001_add_auth_columns_to_usuarios_table
-- ---------------------------------------------------------------------
alter table `usuarios` add `email_verified_at` timestamp null;
alter table `usuarios` add `two_factor_secret` text null;
alter table `usuarios` add `two_factor_recovery_codes` text null;
alter table `usuarios` add `two_factor_confirmed_at` timestamp null;
alter table `usuarios` add `remember_token` varchar(100) null;
alter table `usuarios` add `updated_at` timestamp null;

-- ---------------------------------------------------------------------
-- 2026_11_08_000003_add_editada_coordinador_to_historial_reservas_accion
-- ---------------------------------------------------------------------
alter table `historial_reservas` modify `accion` enum('creada', 'enviada_coordinador', 'correccion_solicitada', 'aprobada_coordinador', 'enviada_admin', 'autorizada', 'denegada', 'cancelada', 'editada_coordinador') not null;

-- ---------------------------------------------------------------------
-- 2026_11_08_000004_add_reserva_id_to_notificaciones_table
-- ---------------------------------------------------------------------
alter table `notificaciones` add `reserva_id` int null after `usuario_id`;
alter table `notificaciones` add constraint `fk_notificacion_reserva` foreign key (`reserva_id`) references `reservas` (`id`) on delete set null;

-- ---------------------------------------------------------------------
-- 2026_11_08_000005_create_requerimientos_tables
-- ---------------------------------------------------------------------
create table `requerimientos` (`id` int not null auto_increment primary key, `nombre` varchar(100) not null, `estatus` enum('activo', 'eliminado') not null default 'activo', `fecha_registro` timestamp null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `requerimientos` add unique `uq_requerimientos_nombre`(`nombre`);
create table `espacio_requerimientos` (`id` int not null auto_increment primary key, `espacio_id` int not null, `requerimiento_id` int not null, `cantidad_disponible` int unsigned not null default '1') default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `espacio_requerimientos` add unique `uq_espacio_requerimiento`(`espacio_id`, `requerimiento_id`);
alter table `espacio_requerimientos` add constraint `fk_er_espacio` foreign key (`espacio_id`) references `espacios` (`id`) on delete cascade;
alter table `espacio_requerimientos` add constraint `fk_er_requerimiento` foreign key (`requerimiento_id`) references `requerimientos` (`id`) on delete cascade;
create table `reserva_detalle_requerimientos` (`id` int not null auto_increment primary key, `reserva_detalle_id` int not null, `requerimiento_id` int not null, `cantidad` int unsigned not null default '1') default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `reserva_detalle_requerimientos` add unique `uq_detalle_requerimiento`(`reserva_detalle_id`, `requerimiento_id`);
alter table `reserva_detalle_requerimientos` add constraint `fk_rdr_detalle` foreign key (`reserva_detalle_id`) references `reserva_detalles` (`id`) on delete cascade;
alter table `reserva_detalle_requerimientos` add constraint `fk_rdr_requerimiento` foreign key (`requerimiento_id`) references `requerimientos` (`id`);

-- ---------------------------------------------------------------------
-- 2026_11_11_000001_add_folio_institucional_columns
-- ---------------------------------------------------------------------
alter table `licenciaturas` add `siglas` varchar(10) null after `nombre`;
alter table `licenciaturas` add unique `uq_licenciaturas_siglas`(`siglas`);
alter table `reservas` add `folio_institucional` varchar(40) null after `folio`;
alter table `reservas` add unique `uq_reservas_folio_inst`(`folio_institucional`);

-- ---------------------------------------------------------------------
-- 2026_11_12_000001_add_editada_admin_to_historial_reservas_accion
-- ---------------------------------------------------------------------
ALTER TABLE `historial_reservas` MODIFY `accion` ENUM('creada', 'enviada_coordinador', 'correccion_solicitada', 'aprobada_coordinador', 'enviada_admin', 'autorizada', 'denegada', 'cancelada', 'editada_coordinador', 'editada_admin') NOT NULL, ALGORITHM=INSTANT, LOCK=DEFAULT;

-- ---------------------------------------------------------------------
-- 2026_11_17_000001_add_cuatrimestres_and_asignaturas_to_reservas
-- ---------------------------------------------------------------------
alter table `reservas` add `cuatrimestres` json null after `cuatrimestre_id`;
alter table `reservas` add `asignaturas` json null after `asignatura_id`;

-- ---------------------------------------------------------------------
-- Control de migraciones de Laravel (todas ya aplicadas en este script)
-- ---------------------------------------------------------------------
create table `migrations` (`id` int unsigned not null auto_increment primary key, `migration` varchar(255) not null, `batch` int not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
insert into `migrations` (`migration`, `batch`) values
  ('0001_01_01_000000_create_users_table', 1),
  ('0001_01_01_000001_create_cache_table', 1),
  ('0001_01_01_000002_create_jobs_table', 1),
  ('0001_01_01_000003_create_roles_and_usuarios_tables', 1),
  ('0001_01_01_000004_create_licenciaturas_and_estados_reserva_tables', 1),
  ('0001_01_01_000005_create_reservas_tables', 1),
  ('0001_01_01_000006_create_notificaciones_table', 1),
  ('2024_01_01_000000_create_passkeys_table', 1),
  ('2025_08_14_170933_add_two_factor_columns_to_users_table', 1),
  ('2026_11_08_000001_add_auth_columns_to_usuarios_table', 1),
  ('2026_11_08_000002_point_passkeys_to_usuarios_table', 1),
  ('2026_11_08_000003_add_editada_coordinador_to_historial_reservas_accion', 1),
  ('2026_11_08_000004_add_reserva_id_to_notificaciones_table', 1),
  ('2026_11_08_000005_create_requerimientos_tables', 1),
  ('2026_11_11_000001_add_folio_institucional_columns', 1),
  ('2026_11_12_000001_add_editada_admin_to_historial_reservas_accion', 1),
  ('2026_11_17_000001_add_cuatrimestres_and_asignaturas_to_reservas', 1);

SET FOREIGN_KEY_CHECKS = 1;
