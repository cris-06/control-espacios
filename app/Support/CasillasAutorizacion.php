<?php

namespace App\Support;

use App\Models\EstadoReserva;
use App\Models\HistorialReserva;
use App\Models\Reserva;
use App\Models\Rol;
use App\Models\User;

/**
 * Las tres casillas de «Autorizaciones» del Formato 10 (docente responsable, coordinación
 * y administración). Las usan el detalle, la edición y la versión imprimible, para que las
 * tres muestren exactamente lo mismo.
 */
class CasillasAutorizacion
{
    /**
     * Derivadas SOLO del estado actual y del historial ya cargado: nadie las escribe. Solo
     * cuenta lo ocurrido después de la última corrección solicitada, así una aprobación
     * anterior a una devolución nunca aparece como vigente. No escribe nada.
     *
     * Requiere cargados: creador.rol, coordinador.rol e historial.usuario.rol.
     *
     * @return list<array{clave: string, titulo: string, situacion: string, etiqueta: string, nombre: string|null, rol: string|null, fecha: string|null}>
     */
    public static function para(Reserva $reserva): array
    {
        $historial = $reserva->historial
            ->sortBy([
                fn (HistorialReserva $a, HistorialReserva $b): int => ($a->fecha?->getTimestamp() ?? 0) <=> ($b->fecha?->getTimestamp() ?? 0),
                fn (HistorialReserva $a, HistorialReserva $b): int => $a->id <=> $b->id,
            ])
            ->values();

        // Ciclo vigente: lo posterior a la última corrección solicitada (o todo, si nunca hubo).
        $ultimaCorreccion = $historial->last(fn (HistorialReserva $h): bool => $h->accion === HistorialReserva::CORRECCION_SOLICITADA);
        $ciclo = $ultimaCorreccion === null
            ? $historial
            : $historial->slice((int) $historial->search(fn (HistorialReserva $h): bool => $h->id === $ultimaCorreccion->id) + 1);
        $ultimo = fn (string ...$acciones): ?HistorialReserva => $ciclo->last(fn (HistorialReserva $h): bool => in_array($h->accion, $acciones, true));

        $rolDe = fn (?User $u): ?string => match ($u?->rol?->nombre) {
            Rol::ADMIN, Rol::SUPERADMIN => 'Administración',
            Rol::COORDINADOR => 'Coordinación',
            Rol::DOCENTE => 'Docente',
            default => null,
        };
        $casilla = fn (string $clave, string $titulo, string $situacion, string $etiqueta, ?HistorialReserva $registro = null, ?User $usuario = null): array => [
            'clave' => $clave,
            'titulo' => $titulo,
            'situacion' => $situacion,
            'etiqueta' => $etiqueta,
            'nombre' => ($registro->usuario ?? $usuario)?->name,
            'rol' => $rolDe($registro->usuario ?? $usuario),
            'fecha' => $registro?->fecha?->toIso8601String(),
        ];

        $estado = $reserva->estado_id;
        $decidida = in_array($estado, [EstadoReserva::PENDIENTE_ADMIN, EstadoReserva::AUTORIZADA, EstadoReserva::DENEGADA], true);
        $devueltaPor = fn (string $rol): ?HistorialReserva => $estado === EstadoReserva::CORRECCION
            && $ultimaCorreccion !== null
            && $rolDe($ultimaCorreccion->usuario) === $rol ? $ultimaCorreccion : null;

        // 1. Docente responsable (quien elaboró y entregó la solicitud).
        $envio = $ultimo(HistorialReserva::ENVIADA_COORDINADOR, HistorialReserva::ENVIADA_ADMIN);
        $docente = match (true) {
            $estado === EstadoReserva::BORRADOR => $casilla('docente', 'Docente responsable', 'pendiente', 'En elaboración, sin enviar', usuario: $reserva->creador),
            $estado === EstadoReserva::CORRECCION => $casilla('docente', 'Docente responsable', 'pendiente', 'Debe atender la corrección y reenviar', usuario: $reserva->creador),
            default => $casilla('docente', 'Docente responsable', 'completada', 'Entregó la solicitud', $envio, $reserva->creador),
        };

        // 2. Coordinación: aprobación vigente o envío directo de una solicitud propia.
        $aprobacion = $decidida ? $ultimo(HistorialReserva::APROBADA_COORDINADOR, HistorialReserva::ENVIADA_ADMIN) : null;
        $coordinacion = match (true) {
            $aprobacion !== null => $casilla(
                'coordinacion', 'Coordinación', 'completada',
                $aprobacion->accion === HistorialReserva::ENVIADA_ADMIN ? 'Solicitud propia enviada a administración' : 'Aprobada por coordinación',
                $aprobacion,
            ),
            $devueltaPor('Coordinación') !== null => $casilla('coordinacion', 'Coordinación', 'devuelta', 'Solicitó corrección', $devueltaPor('Coordinación')),
            $estado === EstadoReserva::PENDIENTE_COORDINADOR => $casilla('coordinacion', 'Coordinación', 'pendiente', 'En revisión', usuario: $reserva->coordinador),
            default => $casilla('coordinacion', 'Coordinación', 'pendiente', 'Pendiente', usuario: $reserva->coordinador),
        };

        // 3. Administración: la decisión final o su devolución.
        $administracion = match (true) {
            $estado === EstadoReserva::AUTORIZADA && ($r = $ultimo(HistorialReserva::AUTORIZADA)) !== null => $casilla('administracion', 'Autorización', 'completada', 'Autorizada', $r),
            $estado === EstadoReserva::DENEGADA && ($r = $ultimo(HistorialReserva::DENEGADA)) !== null => $casilla('administracion', 'Autorización', 'denegada', 'Denegada', $r),
            $devueltaPor('Administración') !== null => $casilla('administracion', 'Autorización', 'devuelta', 'Solicitó corrección', $devueltaPor('Administración')),
            $estado === EstadoReserva::PENDIENTE_ADMIN => $casilla('administracion', 'Autorización', 'pendiente', 'En revisión por administración'),
            $estado === EstadoReserva::AUTORIZADA => $casilla('administracion', 'Autorización', 'completada', 'Autorizada'),
            $estado === EstadoReserva::DENEGADA => $casilla('administracion', 'Autorización', 'denegada', 'Denegada'),
            default => $casilla('administracion', 'Autorización', 'pendiente', 'Pendiente'),
        };

        return [$docente, $coordinacion, $administracion];
    }
}
