<?php

namespace Database\Seeders;

use App\Models\Licenciatura;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Solo para desarrollo: contraseña "password" para todos (el cast 'hashed' del modelo la encripta).
        $usuarios = [
            ['nombre' => 'Super',   'apellido_paterno' => 'Admin',  'correo' => 'superadmin@cuv.test',   'rol' => 'superadmin',  'licenciaturas' => []],
            ['nombre' => 'Ana',     'apellido_paterno' => 'Admin',  'correo' => 'admin@cuv.test',        'rol' => 'admin',       'licenciaturas' => []],
            ['nombre' => 'Carlos',  'apellido_paterno' => 'Coord',  'correo' => 'coordinador1@cuv.test', 'rol' => 'coordinador', 'licenciaturas' => ['Administración', 'Derecho']],
            ['nombre' => 'Laura',   'apellido_paterno' => 'Coord',  'correo' => 'coordinador2@cuv.test', 'rol' => 'coordinador', 'licenciaturas' => ['Ingeniería en Sistemas', 'Psicología']],
            ['nombre' => 'Miguel',  'apellido_paterno' => 'Docente', 'correo' => 'docente1@cuv.test',    'rol' => 'docente',     'licenciaturas' => ['Administración']],
            ['nombre' => 'Sofía',   'apellido_paterno' => 'Docente', 'correo' => 'docente2@cuv.test',    'rol' => 'docente',     'licenciaturas' => ['Derecho']],
            ['nombre' => 'Pedro',   'apellido_paterno' => 'Docente', 'correo' => 'docente3@cuv.test',    'rol' => 'docente',     'licenciaturas' => ['Ingeniería en Sistemas']],
        ];

        foreach ($usuarios as $datos) {
            $licenciaturas = $datos['licenciaturas'];
            $rol = $datos['rol'];
            unset($datos['licenciaturas'], $datos['rol']);

            $usuario = User::firstOrCreate(
                ['correo' => $datos['correo']],
                $datos + [
                    'password' => 'password',
                    'rol_id' => Rol::where('nombre', $rol)->value('id'),
                    'estatus' => 'activo',
                    'debe_cambiar_password' => false,
                ]
            );

            if ($licenciaturas) {
                $ids = Licenciatura::whereIn('nombre', $licenciaturas)->pluck('id')->all();
                $usuario->licenciaturas()->syncWithoutDetaching($ids);
            }
        }
    }
}
