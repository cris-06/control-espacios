# Sistema de reservas — Centro Universitario de Valladolid

Aplicación web para consultar la disponibilidad de los espacios de la universidad y dar seguimiento a las solicitudes de reservación.

**Estado actual:** la interfaz ya funciona, pero muestra **datos de ejemplo**. Todavía no guarda solicitudes ni usa las tablas reales ([ver qué es temporal](#qué-es-temporal)).

Hecho con Laravel 13, Inertia, Vue 3, TypeScript, Tailwind CSS y Fortify.

## Requisitos

- PHP 8.3 o superior, con la extensión `pdo_sqlite` activa
- Composer
- Node.js y npm
- Git

Se probó con PHP 8.4 y Node 24. No necesitas instalar MySQL por ahora: la base de datos local es SQLite y se crea sola.

## Instalación (solo la primera vez)

```bash
git clone -b dev https://github.com/cris-06/control-espacios.git
cd control-espacios
composer setup
```

`composer setup` instala las dependencias, crea tu `.env`, genera la base de datos y compila la interfaz.

## Abrir el sistema

```bash
composer dev
```

Deja esa terminal abierta y entra a <http://localhost:8000>. Para detenerlo, pulsa `Ctrl + C`.

## Cómo entrar

1. En la pantalla de inicio de sesión, pulsa **Regístrate**.
2. Escribe tu nombre, cualquier correo y una contraseña. El correo no se verifica.
3. Entrarás directo al panel.

La base de datos es de cada computadora, así que tu cuenta solo existe en tu equipo.

### Ver el sistema con cada rol

Abre el menú con ☰ y usa **Ver como**, abajo a la izquierda.

| Rol                   | Solicitudes que ve                | Reportes                  |
| --------------------- | --------------------------------- | ------------------------- |
| `superadmin`, `admin` | Todas las enviadas                | Todos                     |
| `coordinador`         | Las enviadas de sus licenciaturas | Solo de sus licenciaturas |
| `docente`             | Las suyas, incluidos borradores   | Sin acceso                |

## Qué hay hoy

| Módulo             | Qué hace                                                           |
| ------------------ | ------------------------------------------------------------------ |
| **Panel**          | Indicadores, pendientes y próximas reservaciones según el rol      |
| **Disponibilidad** | Agenda por fecha, espacio y horario                                |
| **Solicitudes**    | Consulta y filtro por estado                                       |
| **Reportes**       | Filtros por periodo, espacio, licenciatura y estado, con impresión |
| **Configuración**  | Perfil y seguridad de la cuenta                                    |

## Qué es temporal

Esto existe para poder trabajar mientras llegan los módulos reales. Hay que retirarlo o reemplazarlo cuando corresponda.

| Qué                                | Dónde                                                                                                            | Se reemplaza por                                                 |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| Datos ficticios y notificaciones   | `app/Support/DatosEjemplo.php`                                                                                   | Consultas a la base de datos real                                |
| Selector **Ver como**              | `app/Http/Controllers/DemoRolController.php`, `resources/js/components/SelectorRolDemo.vue` y la ruta `demo.rol` | Roles y permisos reales                                          |
| Verificación de correo desactivada | `app/Models/User.php`                                                                                            | Volver a implementar `MustVerifyEmail` antes de publicar         |
| Base de datos SQLite               | `.env`                                                                                                           | MySQL con `BD_Reservas_corregida.sql`, que aún no está conectado |

> **No publiques el sistema así.** Cualquiera puede registrarse sin validar su correo y el selector **Ver como** permite cambiarse a administrador.

## Siguientes pasos por módulo

| Responsable | Qué sigue                                                                                                                     |
| ----------- | ----------------------------------------------------------------------------------------------------------------------------- |
| Cristofer   | Integrar los módulos y mantener las reglas del repositorio                                                                    |
| Lara        | Pasar el SQL a migraciones y modelos, y conectar MySQL. Hay que definir cómo se unen `usuarios` y la tabla `users` de Laravel |
| Dani        | Administración de espacios y catálogos                                                                                        |
| Mendoza     | Formulario de solicitud, flujo de reservaciones y validación de traslapes en el servidor                                      |
| Cristian    | Roles y permisos reales, retirar el selector y decidir qué partes de la autenticación se quedan                               |
| Abi         | Conectar panel, calendario y reportes con los datos reales                                                                    |

## Crear una pantalla nueva

1. Crea el controlador en `app/Http/Controllers` y agrega su ruta en `routes/web.php`, dentro del grupo con `auth`.
2. Crea la página en `resources/js/pages`.
3. Agrégala al menú en `resources/js/components/AppSidebar.vue`.

Usa esta base para que tu pantalla se vea igual a las demás:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
</script>

<template>
    <Head title="Espacios" />

    <PaginaBase titulo="Espacios" descripcion="Administra los espacios.">
        <TarjetaSeccion titulo="Listado">
            <!-- Tu contenido va aquí -->
        </TarjetaSeccion>
    </PaginaBase>
</template>
```

- **Componentes listos:** `CampoFormulario`, `CampoSelect`, `TablaDatos`, `TablaReservas`, `EstadoBadge` y `TarjetaIndicador`.
- **Colores:** usa `bg-rojo` y `text-rojo` para el rojo institucional (`#da153b`) y `bg-primary` para el azul rey. El sistema solo tiene tema claro.
- **Contenido:** ponlo dentro de `TarjetaSeccion`, porque el fondo de la página lleva el logo.
- **Seguridad:** valida los datos y revisa los permisos en el servidor. Ocultar un botón no protege una ruta.

## Antes de subir tus cambios

```bash
composer ci:check
```

Revisa el formato, los tipos y las pruebas. Si falla, corrígelo antes de subir.

1. Parte de `dev` actualizada y crea tu rama: `git switch -c modulo/espacios`.
2. Sube tu rama y pide que la revisen antes de unirla a `dev`.
3. Nunca subas el archivo `.env` ni contraseñas.
4. Si necesitas cambiar una tabla, revísalo antes con Lara y avisa al equipo.

### Después de hacer `git pull`

```bash
composer install
npm install
php artisan migrate
```

## Problemas comunes

| Problema                                        | Solución                                                                                                   |
| ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `could not find driver`                         | Activa `pdo_sqlite` en tu `php.ini`                                                                        |
| `The email has already been taken`              | Esa cuenta ya existe en tu equipo. Inicia sesión con ella                                                  |
| La página sale en blanco o sin estilos          | Revisa que `composer dev` siga abierto. Si no quieres usarlo, borra `public/hot` y ejecuta `npm run build` |
| Quiero empezar de cero                          | Ejecuta `php artisan migrate:fresh`. Borra todas las cuentas de tu equipo                                  |
| No llega el correo para recuperar la contraseña | No se envían correos reales. El enlace queda en `storage/logs/laravel.log`                                 |
