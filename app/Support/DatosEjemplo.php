<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Datos ficticios para construir la interfaz mientras no existan los modelos ni los roles reales.
 *
 * TEMPORAL: los identificadores de estados y roles coinciden con BD_Reservas_corregida.sql.
 * Cuando estén listos los modelos Eloquent y la autorización, esta clase y DemoRolController
 * se eliminan y los controladores deben consultar la base de datos.
 *
 * @phpstan-type FilaReserva array{id: int, folio: string, actividad: string, usuario_creador_id: int, docente: string, licenciatura_id: int, licenciatura: string, espacio_id: int, espacio: string, fecha: string, hora_inicio: string, hora_fin: string, cantidad_alumnos: int, estado_id: int, estado: string}
 */
class DatosEjemplo
{
    public const BORRADOR = 1;

    public const PENDIENTE_COORDINADOR = 2;

    public const CORRECCION = 3;

    public const PENDIENTE_ADMIN = 4;

    public const AUTORIZADA = 5;

    public const DENEGADA = 6;

    public const CANCELADA = 7;

    public const ESTADOS = [
        self::BORRADOR => 'Borrador',
        self::PENDIENTE_COORDINADOR => 'Pendiente de coordinador',
        self::CORRECCION => 'En corrección',
        self::PENDIENTE_ADMIN => 'Pendiente de administración',
        self::AUTORIZADA => 'Autorizada',
        self::DENEGADA => 'Denegada',
        self::CANCELADA => 'Cancelada',
    ];

    public const ROLES = ['superadmin', 'admin', 'coordinador', 'docente'];

    public const LICENCIATURAS = [
        1 => 'Sistemas Computacionales',
        2 => 'Administración',
        3 => 'Derecho',
        4 => 'Psicología',
    ];

    /** Usuario que representa al rol docente en los datos de ejemplo. */
    private const DOCENTE_ID = 3;

    /** Licenciaturas asignadas al rol coordinador en los datos de ejemplo. */
    private const LICENCIATURAS_COORDINADOR = [1, 2];

    private const DOCENTES = [
        3 => 'Laura Méndez',
        4 => 'Jorge Ruiz',
        5 => 'Ana Torres',
    ];

    /**
     * @return array<int, array{id: int, nombre: string, ubicacion: string, capacidad: int, color_calendario: string, estatus: string}>
     */
    public static function espacios(): array
    {
        return [
            1 => ['id' => 1, 'nombre' => 'Auditorio', 'ubicacion' => 'Edificio A', 'capacidad' => 120, 'color_calendario' => '#dc2626', 'estatus' => 'disponible'],
            2 => ['id' => 2, 'nombre' => 'Sala de cómputo 1', 'ubicacion' => 'Edificio B', 'capacidad' => 35, 'color_calendario' => '#2563eb', 'estatus' => 'disponible'],
            3 => ['id' => 3, 'nombre' => 'Laboratorio de redes', 'ubicacion' => 'Edificio B', 'capacidad' => 25, 'color_calendario' => '#16a34a', 'estatus' => 'disponible'],
            4 => ['id' => 4, 'nombre' => 'Aula magna', 'ubicacion' => 'Edificio C', 'capacidad' => 60, 'color_calendario' => '#9333ea', 'estatus' => 'disponible'],
            5 => ['id' => 5, 'nombre' => 'Cancha techada', 'ubicacion' => 'Área deportiva', 'capacidad' => 200, 'color_calendario' => '#ea580c', 'estatus' => 'mantenimiento'],
            6 => ['id' => 6, 'nombre' => 'Sala de juntas', 'ubicacion' => 'Rectoría', 'capacidad' => 12, 'color_calendario' => '#0891b2', 'estatus' => 'inactivo'],
        ];
    }

    /**
     * Cada fila combina una reserva con uno de sus detalles (reservas + reserva_detalles).
     *
     * @return Collection<int, FilaReserva>
     */
    public static function reservas(): Collection
    {
        $filas = [
            // id, creador, licenciatura, espacio, días desde hoy, inicio, fin, alumnos, estado, actividad
            [1, 3, 1, 2, 0, '08:00', '10:00', 30, self::AUTORIZADA, 'Práctica de bases de datos'],
            [2, 4, 2, 1, 0, '09:00', '12:00', 100, self::AUTORIZADA, 'Conferencia de emprendimiento'],
            [3, 3, 1, 3, 0, '11:00', '13:00', 20, self::PENDIENTE_ADMIN, 'Taller de configuración de routers'],
            [4, 5, 3, 4, 0, '10:00', '12:00', 45, self::AUTORIZADA, 'Simulacro de juicio oral'],
            [5, 5, 3, 1, 0, '11:00', '13:00', 80, self::PENDIENTE_COORDINADOR, 'Foro de derechos humanos'],
            [6, 3, 1, 2, 1, '12:00', '14:00', 28, self::PENDIENTE_COORDINADOR, 'Práctica de programación web'],
            [7, 4, 2, 4, 1, '16:00', '18:00', 50, self::PENDIENTE_ADMIN, 'Presentación de planes de negocio'],
            [8, 3, 1, 1, 2, '10:00', '13:00', 90, self::CORRECCION, 'Semana de la ingeniería'],
            [9, 3, 1, 3, 3, '09:00', '11:00', 22, self::BORRADOR, 'Práctica de redes inalámbricas'],
            [10, 4, 1, 2, 4, '15:00', '17:00', 32, self::PENDIENTE_COORDINADOR, 'Asesoría de proyecto integrador'],
            [11, 5, 4, 4, 5, '08:00', '10:00', 40, self::AUTORIZADA, 'Taller de manejo del estrés'],
            [12, 3, 1, 2, 7, '10:00', '12:00', 30, self::AUTORIZADA, 'Examen práctico de bases de datos'],
            [13, 4, 2, 1, -2, '09:00', '11:00', 70, self::AUTORIZADA, 'Plática de finanzas personales'],
            [14, 5, 4, 3, -5, '13:00', '15:00', 20, self::DENEGADA, 'Taller de psicometría'],
            [15, 3, 1, 4, -7, '08:00', '09:00', 35, self::CANCELADA, 'Reunión de grupo'],
        ];

        $hoy = Date::today();

        return collect($filas)->map(fn (array $fila): array => [
            'id' => $fila[0],
            'folio' => sprintf('RES-%04d', $fila[0]),
            'actividad' => $fila[9],
            'usuario_creador_id' => $fila[1],
            'docente' => self::DOCENTES[$fila[1]],
            'licenciatura_id' => $fila[2],
            'licenciatura' => self::LICENCIATURAS[$fila[2]],
            'espacio_id' => $fila[3],
            'espacio' => self::espacios()[$fila[3]]['nombre'],
            'fecha' => $hoy->addDays($fila[4])->toDateString(),
            'hora_inicio' => $fila[5],
            'hora_fin' => $fila[6],
            'cantidad_alumnos' => $fila[7],
            'estado_id' => $fila[8],
            'estado' => self::ESTADOS[$fila[8]],
        ]);
    }

    public static function rolActual(Request $request): string
    {
        $rol = $request->session()->get('rol_demo');

        return in_array($rol, self::ROLES, true) ? $rol : 'admin';
    }

    /**
     * Reservas que cada rol puede consultar: el docente las propias, el coordinador
     * las enviadas de sus licenciaturas y la administración todas las enviadas.
     *
     * @return Collection<int, FilaReserva>
     */
    public static function reservasVisibles(string $rol): Collection
    {
        $reservas = self::reservas();

        return match ($rol) {
            'docente' => $reservas->where('usuario_creador_id', self::DOCENTE_ID)->values(),
            'coordinador' => $reservas
                ->whereIn('licenciatura_id', self::LICENCIATURAS_COORDINADOR)
                ->where('estado_id', '!=', self::BORRADOR)
                ->values(),
            default => $reservas->where('estado_id', '!=', self::BORRADOR)->values(),
        };
    }

    /**
     * Solicitudes que esperan una acción del rol.
     *
     * @return Collection<int, FilaReserva>
     */
    public static function porAtender(string $rol): Collection
    {
        $estados = match ($rol) {
            'docente' => [self::BORRADOR, self::CORRECCION],
            'coordinador' => [self::PENDIENTE_COORDINADOR],
            default => [self::PENDIENTE_ADMIN],
        };

        return self::reservasVisibles($rol)
            ->whereIn('estado_id', $estados)
            ->sortBy('fecha')
            ->values();
    }

    /**
     * Licenciaturas que cada rol puede usar como filtro.
     *
     * @return array<int, string>
     */
    public static function licenciaturasVisibles(string $rol): array
    {
        return $rol === 'coordinador'
            ? array_intersect_key(self::LICENCIATURAS, array_flip(self::LICENCIATURAS_COORDINADOR))
            : self::LICENCIATURAS;
    }
}
