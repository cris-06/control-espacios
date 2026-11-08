<?php

use App\Models\Licenciatura;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

function rolId(string $nombre): int
{
    return Rol::where('nombre', $nombre)->value('id');
}

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosUsuario(array $cambios = []): array
{
    return [
        'nombre' => 'Laura',
        'apellido_paterno' => 'Méndez',
        'apellido_materno' => 'Pech',
        'correo' => 'Laura.Mendez@CUV.mx',
        'telefono' => '9991112233',
        'rol_id' => rolId('docente'),
        'licenciaturas' => [],
        ...$cambios,
    ];
}

beforeEach(function () {
    $this->superadmin = User::factory()->rol('superadmin')->create();
    $this->admin = User::factory()->rol('admin')->create();
    $this->licenciatura = Licenciatura::factory()->create();
});

test('only administration opens user management', function (string $rol, int $estado) {
    $this->actingAs(User::factory()->rol($rol)->create())
        ->get(route('admin.usuarios.index'))
        ->assertStatus($estado);
})->with([
    ['superadmin', 200],
    ['admin', 200],
    ['coordinador', 403],
    ['docente', 403],
]);

test('non administrators cannot create users even posting directly', function (string $rol) {
    $this->actingAs(User::factory()->rol($rol)->create())
        ->post(route('admin.usuarios.store'), datosUsuario())
        ->assertForbidden();

    expect(User::where('correo', 'laura.mendez@cuv.mx')->exists())->toBeFalse();
})->with(['coordinador', 'docente']);

test('an admin creates a user with a temporary password that must be changed', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), datosUsuario(['licenciaturas' => [$this->licenciatura->id]]));

    $response->assertRedirect(route('admin.usuarios.index'))
        ->assertInertiaFlash('passwordTemporal.correo', 'laura.mendez@cuv.mx');

    $usuario = User::where('correo', 'laura.mendez@cuv.mx')->firstOrFail();
    $password = session(SessionKey::FLASH_DATA)['passwordTemporal']['password'];

    expect($usuario->debe_cambiar_password)->toBeTrue()
        ->and($usuario->estatus)->toBe('activo')
        ->and($usuario->nombreRol())->toBe('docente')
        ->and(Hash::check($password, $usuario->password))->toBeTrue()
        ->and($usuario->licenciaturas->pluck('id')->all())->toBe([$this->licenciatura->id]);
});

test('a teacher or coordinator can have several degree programs', function () {
    $otra = Licenciatura::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), datosUsuario([
            'rol_id' => rolId('coordinador'),
            'licenciaturas' => [$this->licenciatura->id, $otra->id],
        ]))
        ->assertSessionHasNoErrors();

    expect(User::where('correo', 'laura.mendez@cuv.mx')->first()->licenciaturas)->toHaveCount(2);
});

test('administrators are not assigned degree programs', function () {
    $this->actingAs($this->superadmin)
        ->post(route('admin.usuarios.store'), datosUsuario([
            'rol_id' => rolId('admin'),
            'licenciaturas' => [$this->licenciatura->id],
        ]))
        ->assertSessionHasNoErrors();

    expect(User::where('correo', 'laura.mendez@cuv.mx')->first()->licenciaturas)->toHaveCount(0);
});

test('inactive degree programs cannot be assigned to new users', function () {
    $inactiva = Licenciatura::factory()->create(['estatus' => 'inactiva']);

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), datosUsuario(['licenciaturas' => [$inactiva->id]]))
        ->assertSessionHasErrors('licenciaturas.0');
});

test('the email must be unique', function () {
    User::factory()->create(['correo' => 'laura.mendez@cuv.mx']);

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), datosUsuario())
        ->assertSessionHasErrors(['correo' => 'Ya existe un usuario con este correo.']);
});

test('an admin cannot create a superadmin', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), datosUsuario(['rol_id' => rolId('superadmin')]))
        ->assertSessionHasErrors(['rol_id' => 'No puedes asignar un rol de nivel mayor al tuyo.']);

    expect(User::where('correo', 'laura.mendez@cuv.mx')->exists())->toBeFalse();
});

test('an admin can create another admin', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.store'), datosUsuario(['rol_id' => rolId('admin')]))
        ->assertSessionHasNoErrors();
});

test('the form only offers roles up to the current user level', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.usuarios.create'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('admin/usuarios/Form')
            ->where('roles', fn ($roles) => collect($roles)->pluck('nombre')->all() === ['admin', 'coordinador', 'docente']));
});

test('an admin cannot edit, deactivate or reset a superadmin', function () {
    $this->actingAs($this->admin);

    $this->get(route('admin.usuarios.edit', $this->superadmin))->assertForbidden();
    $this->put(route('admin.usuarios.update', $this->superadmin), datosUsuario(['rol_id' => rolId('docente')]))->assertForbidden();
    $this->patch(route('admin.usuarios.estatus', $this->superadmin))->assertForbidden();
    $this->post(route('admin.usuarios.password', $this->superadmin))->assertForbidden();

    $this->superadmin->refresh();
    expect($this->superadmin->nombreRol())->toBe('superadmin')
        ->and($this->superadmin->estatus)->toBe('activo');
});

test('an admin cannot promote a user above their own level', function () {
    $docente = User::factory()->rol('docente')->create();

    $this->actingAs($this->admin)
        ->put(route('admin.usuarios.update', $docente), datosUsuario(['correo' => $docente->correo, 'rol_id' => rolId('superadmin')]))
        ->assertSessionHasErrors('rol_id');

    expect($docente->refresh()->nombreRol())->toBe('docente');
});

test('a superadmin manages everyone', function () {
    $otroSuperadmin = User::factory()->rol('superadmin')->create();

    $this->actingAs($this->superadmin)
        ->patch(route('admin.usuarios.estatus', $otroSuperadmin))
        ->assertRedirect();

    expect($otroSuperadmin->refresh()->estatus)->toBe('inactivo');
});

test('an admin edits a user and syncs their degree programs', function () {
    $docente = User::factory()->rol('docente')->create();
    $docente->licenciaturas()->attach($this->licenciatura);
    $nueva = Licenciatura::factory()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.usuarios.update', $docente), datosUsuario([
            'correo' => $docente->correo,
            'nombre' => 'Laura Elena',
            'rol_id' => rolId('coordinador'),
            'licenciaturas' => [$nueva->id],
        ]))
        ->assertRedirect(route('admin.usuarios.index'));

    $docente->refresh();
    expect($docente->nombre)->toBe('Laura Elena')
        ->and($docente->nombreRol())->toBe('coordinador')
        ->and($docente->licenciaturas->pluck('id')->all())->toBe([$nueva->id]);
});

test('a user keeps an inactive degree program they already had', function () {
    $docente = User::factory()->rol('docente')->create();
    $inactiva = Licenciatura::factory()->create(['estatus' => 'inactiva']);
    $docente->licenciaturas()->attach($inactiva);

    $this->actingAs($this->admin)
        ->put(route('admin.usuarios.update', $docente), datosUsuario([
            'correo' => $docente->correo,
            'licenciaturas' => [$inactiva->id],
        ]))
        ->assertSessionHasNoErrors();
});

test('nobody changes their own role', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.usuarios.update', $this->admin), datosUsuario([
            'correo' => $this->admin->correo,
            'rol_id' => rolId('docente'),
        ]))
        ->assertSessionHasErrors(['rol_id' => 'No puedes cambiar tu propio rol.']);

    expect($this->admin->refresh()->nombreRol())->toBe('admin');
});

test('nobody deactivates their own account', function () {
    $this->actingAs($this->admin)
        ->patch(route('admin.usuarios.estatus', $this->admin))
        ->assertForbidden();

    expect($this->admin->refresh()->estatus)->toBe('activo');
});

test('deactivating and reactivating a user', function () {
    $docente = User::factory()->rol('docente')->create();

    $this->actingAs($this->admin)->patch(route('admin.usuarios.estatus', $docente))->assertRedirect();
    expect($docente->refresh()->estatus)->toBe('inactivo');

    $this->actingAs($this->admin)->patch(route('admin.usuarios.estatus', $docente))->assertRedirect();
    expect($docente->refresh()->estatus)->toBe('activo');
});

test('resetting a password issues a new temporary one', function () {
    $docente = User::factory()->rol('docente')->create();

    $this->actingAs($this->admin)
        ->post(route('admin.usuarios.password', $docente))
        ->assertInertiaFlash('passwordTemporal.correo', $docente->correo);

    $password = session(SessionKey::FLASH_DATA)['passwordTemporal']['password'];
    $docente->refresh();

    expect(Hash::check($password, $docente->password))->toBeTrue()
        ->and(Hash::check('password', $docente->password))->toBeFalse()
        ->and($docente->debe_cambiar_password)->toBeTrue();
});

test('the list can be searched and only shows allowed actions', function () {
    User::factory()->rol('docente')->create(['nombre' => 'Zacarías', 'correo' => 'zacarias@cuv.mx']);

    $this->actingAs($this->admin)
        ->get(route('admin.usuarios.index', ['buscar' => 'zacar']))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('usuarios.data', 1)
            ->where('usuarios.data.0.correo', 'zacarias@cuv.mx')
            ->where('usuarios.data.0.puede.editar', true));

    $this->actingAs($this->admin)
        ->get(route('admin.usuarios.index', ['rol_id' => rolId('superadmin')]))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('usuarios.data.0.puede.editar', false)
            ->where('usuarios.data.0.puede.cambiarEstatus', false));
});
