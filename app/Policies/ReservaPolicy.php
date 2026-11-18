<?php

namespace App\Policies;

use App\Models\EstadoReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Permisos sobre reservas por rol.
 *
 * - Docente: crea las suyas, las edita en borrador/corrección y las envía a su
 *   coordinador. No ve reservas ajenas ni revisa, autoriza o deniega.
 * - Coordinador: igual que el docente con sus propias reservas; además ve, edita
 *   (en pendiente_coordinador) y revisa las de SUS licenciaturas.
 * - Admin / superadmin: ven todo y deciden las que están en pendiente_admin; también pueden
 *   editarlas directamente mientras están en ese estado (sin el mínimo de 10 días, ver
 *   ReservaService::actualizar).
 *
 * Las transiciones de estado en sí las valida ReservaWorkflowService (Fase 4);
 * esta policy decide si el usuario puede intentar la acción.
 */
class ReservaPolicy
{
    /** Estados en los que el creador puede editar y enviar. */
    private const EDITABLES_POR_CREADOR = [EstadoReserva::BORRADOR, EstadoReserva::CORRECCION];

    /**
     * Todos los roles tienen un listado; cada uno ve solo lo suyo (alcance en Fase 6).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Reserva $reserva): bool
    {
        if ($this->esCreador($user, $reserva)) {
            return true;
        }

        // Nadie ve borradores ajenos: aún no se los han enviado (mismo criterio que
        // Reserva::scopeVisiblesPara).
        if ($reserva->enEstado(EstadoReserva::BORRADOR)) {
            return false;
        }

        return $user->esAdministrador() || $user->coordinaLicenciatura($reserva->licenciatura_id);
    }

    /**
     * Docentes y coordinadores solicitan espacios. La administración autoriza,
     * así que no crea reservas (evita que autorice las suyas).
     */
    public function create(User $user): bool
    {
        return $user->tieneRol(Rol::DOCENTE, Rol::COORDINADOR);
    }

    public function update(User $user, Reserva $reserva): bool
    {
        // Directivo (admin / superadmin): edición directa SOLO mientras la solicitud espera su
        // decisión (pendiente_admin). Ningún otro estado: ni borradores, ni las que están en
        // coordinación o corrección, ni las ya autorizadas o denegadas.
        // [feature/formato-10] Cambio pendiente de revisión de CristhianKu antes de fusionar.
        // Antes, administración no cumplía ninguna de las condiciones de abajo (no crea reservas
        // y no coordina licenciaturas), así que docentes y coordinadores no cambian.
        if ($user->esAdministrador()) {
            return $reserva->enEstado(EstadoReserva::PENDIENTE_ADMIN);
        }

        if ($this->esCreador($user, $reserva) && $reserva->enEstado(...self::EDITABLES_POR_CREADOR)) {
            return true;
        }

        // El coordinador completa la propuesta antes de enviarla al admin.
        return $user->coordinaLicenciatura($reserva->licenciatura_id)
            && $reserva->enEstado(EstadoReserva::PENDIENTE_COORDINADOR);
    }

    /**
     * Enviar al coordinador: borrador/corrección -> pendiente_coordinador.
     * Solo el creador, y su licenciatura debe tener un coordinador activo.
     */
    public function enviar(User $user, Reserva $reserva): Response
    {
        if (! $this->esCreador($user, $reserva)) {
            return Response::deny('Solo quien creó la reserva puede enviarla a coordinación.');
        }

        if (! $reserva->enEstado(...self::EDITABLES_POR_CREADOR)) {
            return Response::deny('Solo se pueden enviar reservas en borrador o en corrección.');
        }

        if (! $user->perteneceALicenciatura($reserva->licenciatura_id)) {
            return Response::deny('No estás asignado a la licenciatura de esta reserva. Pide al administrador que te asigne a ella.');
        }

        $tieneCoordinador = Licenciatura::query()
            ->whereKey($reserva->licenciatura_id)
            ->whereHas('coordinadores')
            ->exists();

        if (! $tieneCoordinador) {
            return Response::deny('La licenciatura de esta reserva no tiene un coordinador asignado. Pide al administrador que asigne uno.');
        }

        return Response::allow();
    }

    /**
     * Revisión del coordinador: pendiente_coordinador -> pendiente_admin o corrección.
     */
    public function revisar(User $user, Reserva $reserva): Response
    {
        if (! $user->coordinaLicenciatura($reserva->licenciatura_id)) {
            return Response::deny('Solo el coordinador de la licenciatura puede revisar esta reserva.');
        }

        if (! $reserva->enEstado(EstadoReserva::PENDIENTE_COORDINADOR)) {
            return Response::deny('La reserva no está pendiente de revisión por coordinación.');
        }

        return Response::allow();
    }

    /**
     * Decisión de administración: pendiente_admin -> autorizada, denegada o corrección.
     */
    public function autorizar(User $user, Reserva $reserva): Response
    {
        if (! $user->esAdministrador()) {
            return Response::deny('Solo administración puede autorizar o denegar reservas.');
        }

        if (! $reserva->enEstado(EstadoReserva::PENDIENTE_ADMIN)) {
            return Response::deny('La reserva no está pendiente de autorización.');
        }

        return Response::allow();
    }

    private function esCreador(User $user, Reserva $reserva): bool
    {
        return $reserva->usuario_creador_id === $user->id;
    }
}
