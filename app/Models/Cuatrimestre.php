<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de cuatrimestres (Primer cuatrimestre, Segundo, …). Cada licenciatura usa del
 * primero hasta su `cantidad_cuatrimestres`.
 *
 * @property int $id
 * @property int $numero
 * @property string $nombre
 */
class Cuatrimestre extends Model
{
    protected $table = 'cuatrimestres';

    public $timestamps = false;

    protected $fillable = ['numero', 'nombre'];

    /** Ordinales para el nombre (los nueve primeros son los del catálogo original). */
    private const ORDINALES = [
        1 => 'Primer', 2 => 'Segundo', 3 => 'Tercer', 4 => 'Cuarto', 5 => 'Quinto',
        6 => 'Sexto', 7 => 'Séptimo', 8 => 'Octavo', 9 => 'Noveno', 10 => 'Décimo',
        11 => 'Decimoprimer', 12 => 'Decimosegundo', 13 => 'Decimotercer', 14 => 'Decimocuarto',
        15 => 'Decimoquinto', 16 => 'Decimosexto', 17 => 'Decimoséptimo', 18 => 'Decimoctavo',
        19 => 'Decimonoveno', 20 => 'Vigésimo',
    ];

    public static function nombrePara(int $numero): string
    {
        return (self::ORDINALES[$numero] ?? "{$numero}.º").' cuatrimestre';
    }

    /**
     * Agrega al catálogo los cuatrimestres que falten del 1 al $hasta (no cambia los existentes).
     */
    public static function asegurarHasta(int $hasta): void
    {
        $existentes = self::query()->pluck('numero')->map(fn ($n): int => (int) $n)->all();

        foreach (range(1, $hasta) as $numero) {
            if (! in_array($numero, $existentes, true)) {
                self::query()->create(['numero' => $numero, 'nombre' => self::nombrePara($numero)]);
            }
        }
    }
}
