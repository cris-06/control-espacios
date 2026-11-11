<?php

namespace App\Http\Controllers;

use App\Models\EstadoReserva;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\ReservaDetalleRequerimiento;
use App\Services\AnticipacionReserva;
use App\Support\CasillasAutorizacion;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;

/**
 * Versión imprimible del Formato 10 (actividades o eventos), con el membrete del Word
 * institucional. Solo lee: la misma política que el detalle (ReservaPolicy::view) decide
 * quién puede verla, así que nadie imprime borradores ajenos. Se guarda como PDF desde el
 * cuadro de impresión del navegador; no hay generación de PDF en el servidor.
 */
class ImprimirReservaController extends Controller
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private const DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    public function __invoke(Reserva $reserva): View
    {
        Gate::authorize('view', $reserva);

        $reserva->load([
            'creador:id,nombre,apellido_paterno,apellido_materno,rol_id',
            'creador.rol:id,nombre',
            'coordinador:id,nombre,apellido_paterno,apellido_materno,rol_id',
            'coordinador.rol:id,nombre',
            'licenciatura:id,nombre',
            'actividad:id,nombre',
            'cuatrimestre:id,nombre',
            'asignatura:id,nombre',
            'detalles.espacio:id,nombre',
            'detalles.docenteResponsable:id,nombre,apellido_paterno,apellido_materno',
            'detalles.requerimientosPedidos.requerimiento:id,nombre',
            'historial.usuario:id,nombre,apellido_paterno,apellido_materno,rol_id',
            'historial.usuario.rol:id,nombre',
        ]);

        $bloques = array_values($reserva->detalles->map(fn (ReservaDetalle $d): array => $this->bloque($d))->all());
        $casillas = CasillasAutorizacion::para($reserva);

        return view('reservas.imprimir', [
            'reservaId' => $reserva->id,
            'folio' => $reserva->folio,
            'folioInstitucional' => $reserva->folio_institucional,
            'elaboracion' => $reserva->fecha_creacion === null ? null : $this->fechaEncabezado($this->local($reserva->fecha_creacion)),
            'entrega' => $reserva->fecha_entrega_formato === null ? null : $this->fechaEncabezado($reserva->fecha_entrega_formato),
            'estado' => $this->estado($reserva, $casillas),
            'dias' => AnticipacionReserva::DIAS,
            'nombreActividad' => $reserva->nombre_actividad,
            'actividad' => $reserva->actividad->nombre,
            'objetivo' => $reserva->objetivo,
            'licenciatura' => $reserva->licenciatura->nombre,
            'cuatrimestre' => $reserva->cuatrimestre?->nombre,
            'asignatura' => $reserva->asignatura?->nombre,
            'alumnos' => $this->alumnos($bloques),
            'fechas' => array_values(array_unique(array_map(fn (array $b): string => $b['fecha_corta'], $bloques))),
            'horario' => $this->horarioComun($bloques),
            'bloques' => $bloques,
            'costo' => $reserva->costo_alumno === null ? null : '$ '.number_format((float) $reserva->costo_alumno, 2),
            'promocion' => $reserva->promocion_redes === null ? null : mb_strtoupper((string) $reserva->promocion_redes),
            'firmas' => array_map(fn (array $c): array => $this->firma($c), $casillas),
        ]);
    }

    /**
     * @return array{fecha_larga: string, fecha_corta: string, inicio: string, fin: string, espacio: string, lugar: string|null, alumnos: int|null, docente: string|null, descripcion: string|null, material: string|null, observaciones: string|null, requerimientos: list<array{nombre: string, cantidad: int}>}
     */
    private function bloque(ReservaDetalle $detalle): array
    {
        return [
            'fecha_larga' => $this->fechaLarga($detalle->fecha),
            'fecha_corta' => $detalle->fecha->format('d-m-Y'),
            'inicio' => ReservaDetalle::hhmm($detalle->hora_inicio),
            'fin' => ReservaDetalle::hhmm($detalle->hora_fin),
            'espacio' => $detalle->espacio->nombre,
            'lugar' => $this->texto($detalle->lugar_especifico),
            'alumnos' => $detalle->cantidad_alumnos,
            'docente' => $detalle->docenteResponsable?->name,
            'descripcion' => $this->texto($detalle->descripcion_actividad),
            'material' => $this->texto($detalle->material_requerido),
            'observaciones' => $this->texto($detalle->observaciones),
            'requerimientos' => array_values($detalle->requerimientosPedidos
                ->map(fn (ReservaDetalleRequerimiento $p): array => ['nombre' => $p->requerimiento->nombre, 'cantidad' => $p->cantidad])
                ->sortBy('nombre')
                ->values()
                ->all()),
        ];
    }

    /**
     * Con un solo bloque, su cantidad es el total. Con varios no se sabe si son las mismas
     * personas (un grupo puede asistir a varios bloques), así que se presenta como suma por
     * bloque y nunca como total de personas distintas.
     *
     * @param  list<array{alumnos: int|null}>  $bloques
     * @return array{texto: string, nota: string|null}
     */
    private function alumnos(array $bloques): array
    {
        $registrados = array_values(array_filter(array_map(fn (array $b): ?int => $b['alumnos'], $bloques), fn (?int $n): bool => $n !== null));

        if ($registrados === []) {
            return ['texto' => 'Sin registrar', 'nota' => null];
        }

        if (count($bloques) === 1) {
            return ['texto' => $registrados[0].' '.($registrados[0] === 1 ? 'alumno' : 'alumnos'), 'nota' => null];
        }

        $nota = 'Suma de los alumnos indicados en cada bloque; un mismo grupo puede asistir a varios bloques, por lo que no equivale a personas distintas.';

        if (count($registrados) < count($bloques)) {
            $nota .= ' Incluye '.count($registrados).' de '.count($bloques).' bloques con cantidad registrada.';
        }

        return ['texto' => 'Suma por bloque: '.array_sum($registrados), 'nota' => $nota];
    }

    /**
     * Hora de inicio y de final solo si todos los bloques comparten el mismo horario.
     *
     * @param  list<array{inicio: string, fin: string}>  $bloques
     * @return array{inicio: string, fin: string}|null
     */
    private function horarioComun(array $bloques): ?array
    {
        $horarios = array_values(array_unique(array_map(fn (array $b): string => $b['inicio'].'|'.$b['fin'], $bloques)));

        if (count($horarios) !== 1) {
            return null;
        }

        [$inicio, $fin] = explode('|', $horarios[0]);

        return ['inicio' => $inicio, 'fin' => $fin];
    }

    /**
     * Situación de la reserva. Solo una reserva AUTORIZADA se presenta como autorizada; las
     * demás llevan un aviso visible bajo el título del formato.
     *
     * @param  list<array{clave: string, fecha: string|null}>  $casillas
     * @return array{autorizada: bool, titulo: string, detalle: string}
     */
    private function estado(Reserva $reserva, array $casillas): array
    {
        if ($reserva->estado_id === EstadoReserva::AUTORIZADA) {
            $fecha = $casillas[2]['fecha'] ?? null;

            return [
                'autorizada' => true,
                'titulo' => 'SOLICITUD AUTORIZADA',
                'detalle' => $fecha === null ? 'Autorizada en el sistema.' : 'Autorizada en el sistema el '.$this->fechaHora($fecha).'.',
            ];
        }

        [$titulo, $detalle] = match ($reserva->estado_id) {
            EstadoReserva::BORRADOR => ['BORRADOR', 'Solicitud en elaboración, todavía no enviada.'],
            EstadoReserva::PENDIENTE_COORDINADOR => ['PENDIENTE DE REVISIÓN POR COORDINACIÓN', 'Enviada a coordinación, en espera de revisión.'],
            EstadoReserva::CORRECCION => ['EN CORRECCIÓN', 'Devuelta para corregir; debe reenviarse.'],
            EstadoReserva::PENDIENTE_ADMIN => ['PENDIENTE DE AUTORIZACIÓN', 'En espera de la decisión de administración.'],
            EstadoReserva::DENEGADA => ['SOLICITUD RECHAZADA', 'Administración denegó esta solicitud.'],
            default => [mb_strtoupper(EstadoReserva::etiqueta($reserva->estado_id)), ''],
        };

        return [
            'autorizada' => false,
            'titulo' => $titulo,
            'detalle' => trim($detalle.' Este documento no es una autorización.'),
        ];
    }

    /**
     * Casilla de firma: el nombre real de quien corresponde según el estado y lo que el
     * flujo registró. La firma es física: aquí nunca se dibuja una firma ni un sello.
     *
     * @param  array{clave: string, situacion: string, etiqueta: string, nombre: string|null, fecha: string|null}  $casilla
     * @return array{titulo: string, nombre: string|null, situacion: string, detalle: string}
     */
    private function firma(array $casilla): array
    {
        $titulo = match ($casilla['clave']) {
            'docente' => 'Nombre y firma del docente responsable',
            'coordinacion' => 'Nombre y firma del coordinador responsable',
            default => 'Firma de Autorización',
        };

        return [
            'titulo' => $titulo,
            'nombre' => $casilla['nombre'],
            'situacion' => $casilla['situacion'],
            'detalle' => $casilla['etiqueta'].($casilla['fecha'] === null ? '' : ' el '.$this->fechaHora($casilla['fecha'])),
        ];
    }

    private function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    private function local(CarbonInterface $fecha): CarbonInterface
    {
        return $fecha->setTimezone(config('app.timezone'));
    }

    /** «8 JULIO DEL 2025», como en el encabezado del Word. */
    private function fechaEncabezado(CarbonInterface $fecha): string
    {
        return mb_strtoupper($fecha->day.' '.self::MESES[$fecha->month - 1].' del '.$fecha->year);
    }

    /** «martes 22 de julio de 2026». */
    private function fechaLarga(CarbonInterface $fecha): string
    {
        return self::DIAS_SEMANA[$fecha->dayOfWeek].' '.$fecha->day.' de '.self::MESES[$fecha->month - 1].' de '.$fecha->year;
    }

    private function fechaHora(string $iso): string
    {
        return $this->local(Date::parse($iso))->format('d/m/Y H:i');
    }
}
