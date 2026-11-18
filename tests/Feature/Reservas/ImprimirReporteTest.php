<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;

/**
 * Versión imprimible del reporte: membrete institucional, mismos filtros y mismo alcance
 * que el listado de Reportes.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->lsc = Licenciatura::factory()->create(['nombre' => 'Sistemas']);
    $this->derecho = Licenciatura::factory()->create(['nombre' => 'Derecho']);
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach([$this->lsc->id, $this->derecho->id]);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->lsc->id);
    $this->admin = User::factory()->rol('admin')->create();
    $this->espacio = Espacio::factory()->create(['nombre' => 'Auditorio', 'capacidad' => 40]);
    Actividad::factory()->create();

    $this->enviar = function (Licenciatura $licenciatura, string $nombre): Reserva {
        $this->actingAs($this->docente)->post(route('reservas.store'), [
            'licenciatura_id' => $licenciatura->id, 'actividad_id' => Actividad::query()->value('id'),
            'nombre_actividad' => $nombre, 'objetivo' => 'Objetivo',
            'detalles' => [['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 20]],
        ])->assertSessionHasNoErrors();
        $reserva = Reserva::query()->latest('id')->firstOrFail();
        app(W::class)->ejecutar($reserva, $this->docente, W::ENVIAR);

        return $reserva;
    };
});

test('the printable report shows the letterhead, period, summaries and every detail column', function () {
    User::factory()->rol('coordinador')->create()->licenciaturas()->attach($this->derecho->id);
    ($this->enviar)($this->lsc, 'Jornada de redes');

    $this->actingAs($this->admin)
        ->get(route('reportes.imprimir', ['fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-31']))
        ->assertOk()
        ->assertSee('images/membrete_formato10.jpg', false)
        ->assertSeeText('Reporte de reservación de espacios')
        ->assertSeeText('1 de diciembre de 2026 al 31 de diciembre de 2026')
        ->assertSeeInOrder(['Folio', 'Fecha', 'Horario', 'Espacio', 'Actividad', 'Solicitante', 'Licenciatura', 'Estado'])
        ->assertSeeText('Jornada de redes')
        ->assertSeeText('15/12/2026')
        ->assertSeeText('10:00–12:00')
        ->assertSeeText('Uso por espacio')
        ->assertSeeText($this->admin->name);
});

test('the printable report applies the coordinator scope and the filters', function () {
    User::factory()->rol('coordinador')->create()->licenciaturas()->attach($this->derecho->id);
    ($this->enviar)($this->lsc, 'Jornada de redes');
    ($this->enviar)($this->derecho, 'Juicio simulado');

    $this->actingAs($this->coordinador)
        ->get(route('reportes.imprimir', ['fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-31']))
        ->assertOk()
        ->assertSeeText('Jornada de redes')
        ->assertDontSeeText('Juicio simulado');

    $this->actingAs($this->coordinador)
        ->get(route('reportes.imprimir', ['licenciatura_id' => $this->derecho->id]))
        ->assertForbidden();

    $this->actingAs($this->docente)->get(route('reportes.imprimir'))->assertForbidden();
});

test('an invalid period is rejected', function () {
    $this->actingAs($this->admin)
        ->get(route('reportes.imprimir', ['fecha_inicio' => '2026-12-31', 'fecha_fin' => '2026-12-01']))
        ->assertStatus(422);
});
