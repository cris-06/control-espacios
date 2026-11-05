<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // El orden importa: primero los catálogos que otras tablas referencian.
        $this->call([
            RolSeeder::class,
            EstadoReservaSeeder::class,
            CuatrimestreSeeder::class,
            LicenciaturaSeeder::class,
            ActividadSeeder::class,
            RequerimientoSeeder::class,
            EspacioSeeder::class,
            UserSeeder::class,
            ReservaSeeder::class,
        ]);
    }
}
