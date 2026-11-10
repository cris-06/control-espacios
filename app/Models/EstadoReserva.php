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

    /** Etiquetas cortas para la interfaz (estados_reserva.descripcion es una frase). */
    public const ETIQUETAS = [
        self::BORRADOR => 'Borrador',
        self::PENDIENTE_COORDINADOR => 'Pendiente de coordinador',
        self::CORRECCION => 'En corrección',
        self::PENDIENTE_ADMIN => 'Pendiente de administración',
        self::AUTORIZADA => 'Autorizada',
        self::DENEGADA => 'Denegada',
    ];

    public static function etiqueta(int $id): string
    {
        return self::ETIQUETAS[$id] ?? (string) $id;
    }

    /**
     * @return list<array{id: int, nombre: string}>
     */
    public static function opciones(): array
    {
        return array_map(
            fn (int $id, string $nombre): array => ['id' => $id, 'nombre' => $nombre],
            array_keys(self::ETIQUETAS),
            self::ETIQUETAS,
        );
    }

    protected $table = 'estados_reserva';

    public $timestamps = false;

    protected $fillable = ['nombre', 'descripcion', 'orden'];
}
