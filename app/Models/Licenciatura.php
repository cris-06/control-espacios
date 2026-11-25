<?php

namespace App\Models;

use Database\Factories\LicenciaturaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $nombre
 * @property string|null $siglas siglas oficiales (2 a 10 letras A-Z); null si aún no se registran
 * @property string|null $estatus
 * @property int $cantidad_cuatrimestres cuántos cuatrimestres tiene (del primero a este)
 */
class Licenciatura extends Model
{
    /** @use HasFactory<LicenciaturaFactory> */
    use HasFactory;

    protected $table = 'licenciaturas';

    public $timestamps = false;

    /** Siglas oficiales: de 2 a 10 letras mayúsculas, sin espacios ni otros signos. */
    public const PATRON_SIGLAS = '/^[A-Z]{2,10}$/';

    /**
     * Cantidad de cuatrimestres: 9 era el valor fijo del sistema. El límite técnico de 20 cubre
     * cualquier plan cuatrimestral del CUV (un plan de 5 años son 15 cuatrimestres) y mantiene
     * acotado el catálogo y las listas del Formato 10.
     */
    public const CUATRIMESTRES_PREDETERMINADOS = 9;

    public const MAX_CUATRIMESTRES = 20;

    protected $fillable = ['nombre', 'siglas', 'estatus', 'cantidad_cuatrimestres'];

    /** @var array<string, mixed> */
    protected $attributes = ['cantidad_cuatrimestres' => self::CUATRIMESTRES_PREDETERMINADOS];

    protected function casts(): array
    {
        return ['cantidad_cuatrimestres' => 'integer'];
    }

    /**
     * El cuatrimestre más alto que ya usa algún dato de esta licenciatura (reservas como
     * licenciatura principal, licenciaturas participantes o invitadas y asignaturas), con una
     * referencia para el mensaje. Null si no hay ninguno.
     *
     * @return array{numero: int, donde: string}|null
     */
    public function cuatrimestreMasAltoUsado(): ?array
    {
        $numeros = Cuatrimestre::query()->pluck('numero', 'id')->map(fn ($n): int => (int) $n)->all();
        $mayor = null;
        $anotar = function (mixed $cuatrimestreId, string $donde) use (&$mayor, $numeros): void {
            $numero = $numeros[(int) $cuatrimestreId] ?? 0;

            if ($numero > 0 && ($mayor === null || $numero > $mayor['numero'])) {
                $mayor = ['numero' => $numero, 'donde' => $donde];
            }
        };

        Reserva::query()->where('licenciatura_id', $this->id)
            ->where(fn (Builder $q) => $q->whereNotNull('cuatrimestres')->orWhereNotNull('cuatrimestre_id'))
            ->orderBy('id')
            ->each(function (Reserva $r) use ($anotar): void {
                $donde = 'la reserva '.($r->folio ?? Reserva::folioPara($r->id));
                $anotar($r->cuatrimestre_id, $donde);

                foreach ($r->cuatrimestres ?? [] as $c) {
                    $anotar($c['id'], $donde);
                }
            });

        ReservaLicenciatura::query()->with('reserva:id,folio')->where('licenciatura_id', $this->id)->orderBy('id')
            ->each(function (ReservaLicenciatura $l) use ($anotar): void {
                foreach ($l->cuatrimestres ?? [] as $c) {
                    $anotar($c['id'], 'la reserva '.($l->reserva->folio ?? Reserva::folioPara($l->reserva_id)).' (como licenciatura participante o invitada)');
                }
            });

        Asignatura::query()->where('licenciatura_id', $this->id)->get(['cuatrimestre_id', 'nombre'])
            ->each(fn (Asignatura $a) => $anotar($a->cuatrimestre_id, 'la asignatura '.$a->nombre));

        return $mayor;
    }

    /**
     * Usuarios asignados (docentes y coordinadores) vía usuario_licenciaturas.
     *
     * @return BelongsToMany<User, $this>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'usuario_licenciaturas', 'licenciatura_id', 'usuario_id');
    }

    /**
     * Coordinadores activos asignados a esta licenciatura.
     *
     * @return BelongsToMany<User, $this>
     */
    public function coordinadores(): BelongsToMany
    {
        return $this->usuarios()
            ->where('usuarios.estatus', 'activo')
            ->whereHas('rol', fn (Builder $rol) => $rol->where('nombre', Rol::COORDINADOR));
    }
}
