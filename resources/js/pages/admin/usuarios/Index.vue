<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, Power } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import PasswordTemporalDialog from '@/components/PasswordTemporalDialog.vue';
import TablaDatos from '@/components/TablaDatos.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { sinVacios } from '@/lib/reservas';
import {
    create,
    edit,
    estatus,
    index,
    password,
} from '@/routes/admin/usuarios';
import type { Opcion, Paginado, UsuarioFila } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usuarios', href: index() }],
    },
});

const props = defineProps<{
    filtros: { buscar: string; rol_id: number | null; estatus: string | null };
    usuarios: Paginado<UsuarioFila>;
    roles: Opcion[];
}>();

const columnas = [
    { clave: 'name', titulo: 'Nombre' },
    { clave: 'rol', titulo: 'Rol' },
    { clave: 'licenciaturas', titulo: 'Licenciaturas' },
    { clave: 'estatus', titulo: 'Estatus' },
    { clave: 'acciones', titulo: 'Acciones' },
];

const opcionesEstatus: Opcion[] = [
    { id: 1, nombre: 'Activos' },
    { id: 2, nombre: 'Inactivos' },
];
const estatusPorOpcion: Record<string, string> = {
    '1': 'activo',
    '2': 'inactivo',
};

const buscar = ref(props.filtros.buscar);
const rolId = ref(props.filtros.rol_id ? String(props.filtros.rol_id) : null);
const estatusFiltro = ref(
    props.filtros.estatus === 'activo'
        ? '1'
        : props.filtros.estatus === 'inactivo'
          ? '2'
          : null,
);

const filtrar = () => {
    router.get(
        index.url(),
        sinVacios({
            buscar: buscar.value,
            rol_id: rolId.value,
            estatus: estatusFiltro.value
                ? estatusPorOpcion[estatusFiltro.value]
                : null,
        }),
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

watchDebounced(buscar, filtrar, { debounce: 350 });
watch([rolId, estatusFiltro], filtrar);

const conteo = computed(() =>
    props.usuarios.total === 1
        ? '1 usuario'
        : `${props.usuarios.total} usuarios`,
);

const nombreRol = (nombre: string) =>
    nombre.charAt(0).toUpperCase() + nombre.slice(1);

const cambiarEstatus = (usuario: UsuarioFila) => {
    const accion = usuario.estatus === 'activo' ? 'inactivar' : 'activar';

    if (!window.confirm(`¿Seguro que quieres ${accion} a ${usuario.name}?`)) {
        return;
    }

    router.patch(estatus.url(usuario.id), {}, { preserveScroll: true });
};

const restablecerPassword = (usuario: UsuarioFila) => {
    if (
        !window.confirm(
            `Se generará una contraseña temporal nueva para ${usuario.name} y la actual dejará de funcionar. ¿Continuar?`,
        )
    ) {
        return;
    }

    router.post(password.url(usuario.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Usuarios" />

    <PaginaBase
        titulo="Usuarios"
        descripcion="Crea cuentas, asigna roles y licenciaturas, y activa o inactiva el acceso."
    >
        <template #acciones>
            <Button as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    Nuevo usuario
                </Link>
            </Button>
        </template>

        <TarjetaSeccion titulo="Listado" :descripcion="conteo">
            <template #acciones>
                <div class="grid w-full gap-3 sm:w-auto sm:grid-cols-3">
                    <CampoFormulario id="buscar" etiqueta="Buscar">
                        <Input
                            id="buscar"
                            v-model="buscar"
                            type="search"
                            placeholder="Nombre o correo"
                        />
                    </CampoFormulario>
                    <CampoFormulario id="rol" etiqueta="Rol">
                        <CampoSelect
                            id="rol"
                            v-model="rolId"
                            :opciones="
                                roles.map((r) => ({
                                    ...r,
                                    nombre: nombreRol(r.nombre),
                                }))
                            "
                            vacio="Todos los roles"
                        />
                    </CampoFormulario>
                    <CampoFormulario id="estatus" etiqueta="Estatus">
                        <CampoSelect
                            id="estatus"
                            v-model="estatusFiltro"
                            :opciones="opcionesEstatus"
                            vacio="Todos"
                        />
                    </CampoFormulario>
                </div>
            </template>

            <TablaDatos
                :columnas="columnas"
                :filas="usuarios.data"
                vacio="No hay usuarios con estos filtros."
            >
                <template #name="{ fila }">
                    <div class="font-medium">{{ fila.name }}</div>
                    <div class="text-xs text-muted-foreground">
                        {{ fila.correo }}
                    </div>
                    <div
                        v-if="fila.debe_cambiar_password"
                        class="mt-1 text-xs text-amber-700"
                    >
                        Contraseña temporal pendiente de cambio
                    </div>
                </template>
                <template #rol="{ fila }">
                    {{ nombreRol(fila.rol.nombre) }}
                </template>
                <template #licenciaturas="{ fila }">
                    <span
                        v-if="fila.licenciaturas.length === 0"
                        class="text-muted-foreground"
                        >—</span
                    >
                    <div v-else class="flex flex-wrap gap-1">
                        <Badge
                            v-for="nombre in fila.licenciaturas"
                            :key="nombre"
                            variant="outline"
                            >{{ nombre }}</Badge
                        >
                    </div>
                </template>
                <template #estatus="{ fila }">
                    <Badge
                        variant="outline"
                        :class="
                            fila.estatus === 'activo'
                                ? 'border-green-200 bg-green-50 text-green-800'
                                : 'border-border bg-muted text-muted-foreground'
                        "
                    >
                        {{ fila.estatus === 'activo' ? 'Activo' : 'Inactivo' }}
                    </Badge>
                </template>
                <template #acciones="{ fila }">
                    <div class="flex flex-wrap gap-1">
                        <Button
                            v-if="fila.puede.editar"
                            variant="ghost"
                            size="sm"
                            as-child
                        >
                            <Link :href="edit(fila.id)">
                                <Pencil class="size-4" />
                                Editar
                            </Link>
                        </Button>
                        <Button
                            v-if="fila.puede.cambiarEstatus"
                            variant="ghost"
                            size="sm"
                            @click="cambiarEstatus(fila)"
                        >
                            <Power class="size-4" />
                            {{
                                fila.estatus === 'activo'
                                    ? 'Inactivar'
                                    : 'Activar'
                            }}
                        </Button>
                        <Button
                            v-if="fila.puede.restablecerPassword"
                            variant="ghost"
                            size="sm"
                            @click="restablecerPassword(fila)"
                        >
                            <KeyRound class="size-4" />
                            Nueva contraseña
                        </Button>
                    </div>
                </template>
            </TablaDatos>

            <template v-if="usuarios.last_page > 1" #pie>
                <div class="flex items-center justify-between gap-3">
                    <span>
                        {{ usuarios.from }}–{{ usuarios.to }} de
                        {{ usuarios.total }}
                    </span>
                    <div class="flex gap-2">
                        <Button
                            v-if="usuarios.prev_page_url"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="usuarios.prev_page_url" preserve-scroll
                                >Anterior</Link
                            >
                        </Button>
                        <Button v-else variant="outline" size="sm" disabled
                            >Anterior</Button
                        >
                        <Button
                            v-if="usuarios.next_page_url"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="usuarios.next_page_url" preserve-scroll
                                >Siguiente</Link
                            >
                        </Button>
                        <Button v-else variant="outline" size="sm" disabled
                            >Siguiente</Button
                        >
                    </div>
                </div>
            </template>
        </TarjetaSeccion>
    </PaginaBase>

    <PasswordTemporalDialog />
</template>
