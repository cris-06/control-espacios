<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();
    $correoOriginal = $user->correo;

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'nombre' => 'Ana',
            'apellido_paterno' => 'López',
            'apellido_materno' => 'Ku',
            'telefono' => '9991234567',
            'correo' => 'otro@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Ana López Ku');
    expect($user->telefono)->toBe('9991234567');
    // El correo lo administra el admin: no cambia desde el perfil.
    expect($user->correo)->toBe($correoOriginal);
});

test('nombre and apellido paterno are required', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'nombre' => '',
            'apellido_paterno' => '',
        ])
        ->assertSessionHasErrors(['nombre', 'apellido_paterno']);
});
