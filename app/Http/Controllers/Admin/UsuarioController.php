<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UsuarioRequest;
use App\Models\Licenciatura;
use App\Models\Rol;
use App\Models\User;
use App\Support\PasswordTemporal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de usuarios para admin y superadmin (rutas bajo rol:admin,superadmin).
 * Cada acción además pasa por UserPolicy.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        /** @var User $actual */
        $actual = $request->user();

        $filtros = [
            'buscar' => trim((string) $request->query('buscar', '')),
            'rol_id' => $request->integer('rol_id') ?: null,
            'estatus' => in_array($request->query('estatus'), ['activo', 'inactivo'], true) ? $request->query('estatus') : null,
        ];

        $usuarios = User::query()
            ->with(['rol', 'licenciaturas:id,nombre'])
            ->when($filtros['buscar'] !== '', function (Builder $query) use ($filtros) {
                $termino = '%'.$filtros['buscar'].'%';
                $query->where(fn (Builder $q) => $q
                    ->where('nombre', 'like', $termino)
                    ->orWhere('apellido_paterno', 'like', $termino)
                    ->orWhere('apellido_materno', 'like', $termino)
                    ->orWhere('correo', 'like', $termino));
            })
            ->when($filtros['rol_id'], fn (Builder $query, int $rolId) => $query->where('rol_id', $rolId))
            ->when($filtros['estatus'], fn (Builder $query, string $estatus) => $query->where('estatus', $estatus))
            ->orderBy('apellido_paterno')
            ->orderBy('nombre')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $usuario): array => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'correo' => $usuario->correo,
                'telefono' => $usuario->telefono,
                'rol' => $usuario->rol->only(['id', 'nombre', 'nivel']),
                'estatus' => $usuario->estatus,
                'licenciaturas' => $usuario->licenciaturas->pluck('nombre'),
                'debe_cambiar_password' => (bool) $usuario->debe_cambiar_password,
                'puede' => [
                    'editar' => $actual->can('update', $usuario),
                    'cambiarEstatus' => $actual->can('cambiarEstatus', $usuario),
                    'restablecerPassword' => $actual->can('restablecerPassword', $usuario),
                ],
            ]);

        return Inertia::render('admin/usuarios/Index', [
            'filtros' => $filtros,
            'usuarios' => $usuarios,
            'roles' => Rol::query()->orderByDesc('nivel')->get(['id', 'nombre']),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('admin/usuarios/Form', [
            'usuario' => null,
            ...$this->opciones($request->user()),
        ]);
    }

    public function store(UsuarioRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $password = PasswordTemporal::generar();

        $usuario = DB::transaction(function () use ($request, $password): User {
            $usuario = new User($request->safe()->except('licenciaturas'));
            $usuario->password = $password;
            $usuario->estatus = 'activo';
            $usuario->debe_cambiar_password = true;
            $usuario->save();

            $usuario->licenciaturas()->sync($request->licenciaturasParaSincronizar());

            return $usuario;
        });

        Inertia::flash('passwordTemporal', [
            'name' => $usuario->name,
            'correo' => $usuario->correo,
            'password' => $password,
        ]);

        return to_route('admin.usuarios.index');
    }

    public function edit(Request $request, User $usuario): Response
    {
        Gate::authorize('update', $usuario);

        $usuario->load('licenciaturas:id');

        return Inertia::render('admin/usuarios/Form', [
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'apellido_paterno' => $usuario->apellido_paterno,
                'apellido_materno' => $usuario->apellido_materno,
                'correo' => $usuario->correo,
                'telefono' => $usuario->telefono,
                'rol_id' => $usuario->rol_id,
                'estatus' => $usuario->estatus,
                'licenciaturas' => $usuario->licenciaturas->pluck('id'),
                'esYoMismo' => $usuario->is($request->user()),
            ],
            ...$this->opciones($request->user(), $usuario),
        ]);
    }

    public function update(UsuarioRequest $request, User $usuario): RedirectResponse
    {
        Gate::authorize('update', $usuario);

        DB::transaction(function () use ($request, $usuario): void {
            $usuario->update($request->safe()->except('licenciaturas'));
            $usuario->licenciaturas()->sync($request->licenciaturasParaSincronizar());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Usuario actualizado.']);

        return to_route('admin.usuarios.index');
    }

    /**
     * Activa o inactiva. Un usuario inactivo no puede iniciar sesión y, si tenía
     * sesión abierta, EnsureUserIsActive la cierra en su siguiente petición.
     */
    public function cambiarEstatus(User $usuario): RedirectResponse
    {
        Gate::authorize('cambiarEstatus', $usuario);

        $usuario->update(['estatus' => $usuario->estaActivo() ? 'inactivo' : 'activo']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $usuario->estaActivo() ? "{$usuario->name} fue activado." : "{$usuario->name} fue inactivado.",
        ]);

        return back();
    }

    /**
     * Genera una nueva contraseña temporal y obliga a cambiarla al entrar.
     */
    public function restablecerPassword(User $usuario): RedirectResponse
    {
        Gate::authorize('restablecerPassword', $usuario);

        $password = PasswordTemporal::generar();

        $usuario->forceFill([
            'password' => $password,
            'debe_cambiar_password' => true,
            'remember_token' => null,
        ])->save();

        Inertia::flash('passwordTemporal', [
            'name' => $usuario->name,
            'correo' => $usuario->correo,
            'password' => $password,
        ]);

        return back();
    }

    /**
     * Roles asignables y licenciaturas para el formulario.
     *
     * @return array<string, mixed>
     */
    private function opciones(User $actual, ?User $usuario = null): array
    {
        $asignadas = $usuario?->licenciaturas->pluck('id')->all() ?? [];

        return [
            'roles' => Rol::query()->asignablesPor($actual)->get(['id', 'nombre', 'nivel'])
                ->map(fn (Rol $rol): array => [
                    'id' => $rol->id,
                    'nombre' => $rol->nombre,
                    'usaLicenciaturas' => $rol->usaLicenciaturas(),
                ]),
            'licenciaturas' => Licenciatura::query()
                ->where('estatus', 'activa')
                ->when($asignadas !== [], fn (Builder $query) => $query->orWhereIn('id', $asignadas))
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'estatus']),
        ];
    }
}
