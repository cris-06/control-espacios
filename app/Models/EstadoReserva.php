<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo estados_reserva. Los ids coinciden con los registros de la base.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $orden
 */
class EstadoReserva extends Model
{
    public const BORRADOR = 1;

    public const PENDIENTE_COORDINADOR = 2;

    public const CORRECCION = 3;

    public const PENDIENTE_ADMIN = 4;

    public const AUTORIZADA = 5;

    public const DENEGADA = 6;

    /** Agregado por 2026_11_23_000001: administración canceló una reserva autorizada. */
    public const CANCELADA = 7;

    /** Etiquetas cortas para la interfaz (estados_reserva.descripcion es una frase). */
    public const ETIQUETAS = [
        self::BORRADOR => 'Borrador',
        self::PENDIENTE_COORDINADOR => 'Pendiente de coordinador',
        self::CORRECCION => 'En corrección',
        self::PENDIENTE_ADMIN => 'Pendiente de administración',
        self::AUTORIZADA => 'Autorizada',
        self::DENEGADA => 'Denegada',
        self::CANCELADA => 'Cancelada',
    ];

    public static function etiqueta(int $id): string
    {
        return self::ETIQUETAS[$id] ?? (string) $id;
    }

    /**
     * @param  list<int>|null  $ids  solo estos estados (en el orden del flujo); null = todos
     * @return list<array{id: int, nombre: string}>
     */
    public static function opciones(?array $ids = null): array
    {
        $etiquetas = $ids === null
            ? self::ETIQUETAS
            : array_filter(self::ETIQUETAS, fn (int $id): bool => in_array($id, $ids, true), ARRAY_FILTER_USE_KEY);

        return array_map(
            fn (int $id, string $nombre): array => ['id' => $id, 'nombre' => $nombre],
            array_keys($etiquetas),
            $etiquetas,
        );
    }

    /**
     * Estados que ofrece el filtro de Solicitudes a cada rol: los de la etapa en que actúa y
     * los resultados finales. El superadministrador los tiene todos. Solo acota el filtro: el
     * listado sigue mostrando cada solicitud visible con su estado real.
     *
     * @return list<int>
     */
    public static function delFiltroPara(User $user): array
    {
        $finales = [self::AUTORIZADA, self::DENEGADA, self::CANCELADA];

        return match (true) {
            $user->esSuperadmin() => array_keys(self::ETIQUETAS),
            $user->esAdministrador() => [self::CORRECCION, self::PENDIENTE_ADMIN, ...$finales],
            default => [self::BORRADOR, self::PENDIENTE_COORDINADOR, self::CORRECCION, ...$finales],
        };
    }

    protected $table = 'estados_reserva';

    public $timestamps = false;

    protected $fillable = ['nombre', 'descripcion', 'orden'];
}
