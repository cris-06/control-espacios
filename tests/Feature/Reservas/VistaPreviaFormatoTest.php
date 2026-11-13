<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * VER SOLICITUD = vista previa real del Formato 10: la misma plantilla y la misma ruta que la
 * impresión (?vista=previa), con los mismos permisos. La gestión del flujo va aparte.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->lsc = Licenciatura::factory()->create(['nombre' => 'Sistemas', 'siglas' => 'LSC']);
    $this->otra = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->lsc->id);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->lsc->id);
    $this->coordinadorAjeno = User::factory()->rol('coordinador')->create();
    $this->coordinadorAjeno->licenciaturas()->attach($this->otra->id);
    $this->admin = User::factory()->rol('admin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['nombre' => 'Auditorio', 'capacidad' => 100]);

    $this->crear = function (int $bloques = 1): Reserva {
        $detalles = [];
        for ($i = 0; $i < $bloques; $i++) {
            $detalles[] = ['espacio_id' => $this->espacio->id, 'fecha' => Date::parse('2026-12-01')->addDays($i)->toDateString(), 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 30,
                'descripcion_actividad' => str_repeat("Actividad del bloque {$i}. ", 30)];
        }
        $this->actingAs($this->docente)->post(route('reservas.store'), [
            'licenciatura_id' => $this->lsc->id, 'actividad_id' => $this->actividad->id,
            'nombre_actividad' => 'Jornada', 'objetivo' => 'Objetivo', 'detalles' => $detalles,
        ])->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };
    $this->previa = fn (User $u, Reserva $r) => $this->actingAs($u)->get(route('reservas.imprimir', ['reserva' => $r, 'vista' => 'previa']));
    $this->hoja = function (string $html): string {
        preg_match('#<div class="hoja">.*?</table>\s*</div>\s*(?=@|<style|</body>)#s', $html, $m);

        return trim($m[0] ?? '');
    };
});

test('VER renders the detail page with the preview of the same printable route', function () {
    $r = ($this->crear)();

    $this->actingAs($this->docente)->get(route('reservas.show', $r))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('reservas/Show')->where('reserva.id', $r->id));

    $vista = (string) file_get_contents(resource_path('js/pages/reservas/Show.vue'));
    expect($vista)->toContain("imprimir.url(props.reserva.id, {\n    query: { vista: 'previa' },")
        ->toContain('Volver atrás')
        ->toContain(':href="solicitudes()"')
        ->toContain('imprimirDocumento')
        // La gestión es un panel aparte y no hay un segundo diseño del Formato 10 en Vue.
        ->toContain('aria-label="Gestión de la solicitud"')
        ->not->toContain('EncabezadoFormato')
        ->not->toContain('v-html');
});

test('preview and print use the same template and the same data', function () {
    $r = ($this->crear)(3);

    $previa = ($this->previa)($this->docente, $r)->assertOk()->getContent();
    $impresion = $this->actingAs($this->docente)->get(route('reservas.imprimir', $r))->assertOk()->getContent();

    expect(($this->hoja)($previa))->not->toBe('')
        ->and(($this->hoja)($previa))->toBe(($this->hoja)($impresion))
        ->and(substr_count($previa, 'FORMATO 10 DE ACTIVIDADES O EVENTOS'))->toBe(2)
        ->and($previa)->toContain($r->folio_institucional);
});

test('the preview has no web controls and paginates; the print view is unchanged', function () {
    $r = ($this->crear)();

    $previa = ($this->previa)($this->docente, $r)->getContent();
    $impresion = $this->actingAs($this->docente)->get(route('reservas.imprimir', $r))->getContent();

    expect($previa)->toContain('<body class="previa">')
        ->toContain('formato10-altura')
        ->toContain("area.style.top = arriba + 'cm'")
        ->not->toContain('Opciones de impresión')
        ->not->toContain('Volver a la reserva')
        ->and($impresion)->toContain('Opciones de impresión')
        ->not->toContain('formato10-altura')
        ->not->toContain('class="previa"');
});

test('the preview follows the same view permissions as the print view', function () {
    $borrador = ($this->crear)();

    ($this->previa)($this->docente, $borrador)->assertOk();
    ($this->previa)($this->coordinador, $borrador)->assertForbidden();
    ($this->previa)($this->admin, $borrador)->assertForbidden();

    app(W::class)->ejecutar($borrador, $this->docente, W::ENVIAR);

    ($this->previa)($this->coordinador, $borrador)->assertOk();
    ($this->previa)($this->admin, $borrador)->assertOk();
    ($this->previa)($this->coordinadorAjeno, $borrador)->assertForbidden();

    auth()->logout();
    $this->get(route('reservas.imprimir', ['reserva' => $borrador, 'vista' => 'previa']))->assertRedirect(route('login'));
});

test('the status notice is kept in the preview', function () {
    $r = ($this->crear)();
    app(W::class)->ejecutar($r, $this->docente, W::ENVIAR);

    expect(($this->previa)($this->coordinador, $r)->getContent())
        ->toContain('PENDIENTE DE REVISIÓN POR COORDINACIÓN')
        ->toContain('Este documento no es una autorización.');
});

test('the digital form header uses the official title', function () {
    expect((string) file_get_contents(resource_path('js/components/EncabezadoFormato.vue')))
        ->toContain('FORMATO 10 DE ACTIVIDADES O EVENTOS');
});
