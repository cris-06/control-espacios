<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('a user with a temporary password is sent to change it', function () {
    $user = User::factory()->rol('docente')->create(['debe_cambiar_password' => true]);

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('password.cambiar'));
    $this->get(route('solicitudes.index'))->assertRedirect(route('password.cambiar'));
    $this->get(route('password.cambiar'))->assertOk();
});

test('a user with a temporary password can still log out', function () {
    $user = User::factory()->rol('docente')->create(['debe_cambiar_password' => true]);

    $this->actingAs($user)->post(route('logout'))->assertRedirect();

    $this->assertGuest();
});

test('changing the temporary password unlocks the app', function () {
    $user = User::factory()->rol('docente')->create(['debe_cambiar_password' => true]);

    $this->actingAs($user)
        ->put(route('password.cambiar.update'), [
            'current_password' => 'password',
            'password' => 'nueva-contraseña-segura',
            'password_confirmation' => 'nueva-contraseña-segura',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user->refresh();
    expect($user->debe_cambiar_password)->toBeFalse()
        ->and(Hash::check('nueva-contraseña-segura', $user->password))->toBeTrue();

    $this->get(route('dashboard'))->assertOk();
});

test('the temporary password must be provided to change it', function () {
    $user = User::factory()->rol('docente')->create(['debe_cambiar_password' => true]);

    $this->actingAs($user)
        ->put(route('password.cambiar.update'), [
            'current_password' => 'incorrecta',
            'password' => 'nueva-contraseña-segura',
            'password_confirmation' => 'nueva-contraseña-segura',
        ])
        ->assertSessionHasErrors('current_password');

    expect($user->refresh()->debe_cambiar_password)->toBeTrue();
});

test('users without a temporary password skip the change screen', function () {
    $this->actingAs(User::factory()->rol('docente')->create())
        ->get(route('password.cambiar'))
        ->assertRedirect(route('dashboard'));
});
