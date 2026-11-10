<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;

class RolSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['nombre' => 'superadmin',  'nivel' => 100, 'descripcion' => 'Control total del sistema'],
            ['nombre' => 'admin',       'nivel' => 90,  'descripcion' => 'Autoriza o deniega solicitudes y administra catálogos y usuarios'],
            ['nombre' => 'coordinador', 'nivel' => 70,  'descripcion' => 'Revisa solicitudes de los docentes de sus licenciaturas'],
            ['nombre' => 'docente',     'nivel' => 50,  'descripcion' => 'Llena formatos y los envía a su coordinador'],
        ];

        foreach ($roles as $rol) {
            Rol::updateOrCreate(['nombre' => $rol['nombre']], $rol);
        }
    }
}
