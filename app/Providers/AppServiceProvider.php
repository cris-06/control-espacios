<?php

namespace App\Providers;

use App\Models\Rol;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
    }

    /**
     * Permisos generales basados en roles.nivel. Los permisos sobre una reserva
     * concreta están en App\Policies\ReservaPolicy.
     */
    protected function configureGates(): void
    {
        // Gestión de usuarios, roles, licenciaturas y catálogos (admin y superadmin).
        Gate::define('administrar', fn (User $user): bool => $user->tieneNivel(Rol::NIVEL_ADMIN));

        // Reportes: coordinación y administración.
        Gate::define('ver-reportes', fn (User $user): bool => $user->tieneNivel(Rol::NIVEL_COORDINADOR));

        // Un usuario solo puede gestionar a otro de nivel menor o igual al suyo.
        Gate::define('gestionar-usuario', fn (User $user, User $objetivo): bool => $user->tieneNivel(Rol::NIVEL_ADMIN)
            && $objetivo->nivel() <= $user->nivel());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
