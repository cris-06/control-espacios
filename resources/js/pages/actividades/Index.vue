
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    Ellipsis,
    Pencil,
    Plus,
    RotateCcw,
    Search,
} from '@lucide/vue';

import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import TablaDatos from '@/components/TablaDatos.vue';

import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type Actividad = {
    id: number;
    nombre: string;
    estatus: 'activa' | 'eliminada';
};

const props = defineProps<{
    actividades: Actividad[];
}>();

// Formulario de registro

const habilitarEscritura = false;

// Formulario para registrar actividades
const formNuevo = useForm({
    nombre: '',
});

// Registrar actividad en Laravel
const guardarActividad = () => {
    if (!habilitarEscritura || formNuevo.processing) {
        return;
    }

    formNuevo.post('/actividades', {
        preserveScroll: true,
        onSuccess: () => {
            formNuevo.reset();
        },
    });
};

// Búsquedas y filtros
const busqueda = ref('');
const filtroEstatus = ref('todos');

// Formulario de edición
const actividadEditarId = ref<number | null>(null);
const formEdicion = useForm({
    nombre: '',
});
// Cambio de estatus

 // Cambio de estatus
const actividadEstadoId = ref<number | null>(null);

const formEstado = useForm({
    estatus: 'activa' as Actividad['estatus'],
});


const columnas = [
    { clave: 'nombre', titulo: 'Actividad' },
    { clave: 'estatus', titulo: 'Estatus' },
    { clave: 'acciones', titulo: 'Acciones' },
];

const actividadesFiltradas = computed(() => {
    const texto = busqueda.value.trim().toLowerCase();

    return props.actividades.filter((actividad) => {
        const coincideNombre = actividad.nombre
            .toLowerCase()
            .includes(texto);

        const coincideEstatus =
            filtroEstatus.value === 'todos' ||
            actividad.estatus === filtroEstatus.value;

        return coincideNombre && coincideEstatus;
    });
});

const hayFiltrosActivos = computed(() =>
    busqueda.value.trim() !== '' ||
    filtroEstatus.value !== 'todos'
);

const limpiarFiltros = () => {
    busqueda.value = '';
    filtroEstatus.value = 'todos';
};

const actividadEditar = computed(() =>
    props.actividades.find(
        (actividad) => actividad.id === actividadEditarId.value
    ) ?? null
);


const abrirEdicion = (actividad: Actividad) => {
    actividadEditarId.value = actividad.id;

    formEdicion.nombre = actividad.nombre;
    formEdicion.clearErrors();

    actividadEstadoId.value = null;
};

// Actualizar actividad en Laravel
const guardarEdicion = () => {
    if (
        !habilitarEscritura ||
        formEdicion.processing ||
        actividadEditarId.value === null
    ) {
        return;
    }

    formEdicion.put(
        `/actividades/${actividadEditarId.value}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                actividadEditarId.value = null;
            },
        }
    );
};


const actividadCambiarEstado = computed(() =>
    props.actividades.find(
        (actividad) => actividad.id === actividadEstadoId.value
    ) ?? null
);


const abrirCambioEstado = (actividad: Actividad) => {
    actividadEstadoId.value = actividad.id;
    formEstado.estatus = actividad.estatus;
    formEstado.clearErrors();

    actividadEditarId.value = null;
};

// Guardar el cambio de estatus en Laravel
const guardarEstado = () => {
    if (
        !habilitarEscritura ||
        formEstado.processing ||
        actividadEstadoId.value === null
    ) {
        return;
    }

    formEstado.patch(
        `/actividades/${actividadEstadoId.value}/estatus`,
        {
            preserveScroll: true,
            onSuccess: () => {
                actividadEstadoId.value = null;
            },
        }
    );
};

</script>

<template>
    <Head title="Catálogo de actividades" />

    <PaginaBase
        titulo="Catálogo de actividades"
        descripcion="Administra las actividades que se pueden realizar en los espacios universitarios."
    >
        <TarjetaSeccion titulo="Administración de actividades">
            <div class="space-y-5 p-4">

                <!-- AGREGAR ACTIVIDAD -->
                <form class="space-y-3" @submit.prevent="guardarActividad">
                    <h3 class="text-sm font-semibold">
                        Agregar actividad
                    </h3>

                    <div>
                        <label
                            for="nueva-actividad"
                            class="mb-1 block text-sm font-medium"
                        >
                            Nombre de la actividad
                        </label>

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="nueva-actividad"
                                v-model="formNuevo.nombre"
                                maxlength="150"
                                placeholder="Ej. Conferencia, taller, clase..."
                                required
                            />
                            <p
    v-if="formNuevo.errors.nombre"
    class="mt-1 text-sm text-red-600"
>
    {{ formNuevo.errors.nombre }}
</p>


<Button
    type="submit"
    :disabled="!habilitarEscritura || formNuevo.processing"
>
    {{
        formNuevo.processing
            ? 'Guardando...'
            : 'Agregar'
    }}
</Button>

                        </div>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        El registro se habilitará cuando estén
                        integrados los permisos de administrador.
                    </p>
                </form>

                <!-- BUSCADOR Y FILTROS -->
                <div class="space-y-3 border-t pt-4">
                    <h3 class="text-sm font-semibold">
                        Actividades registradas
                    </h3>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                Buscar actividad
                            </label>

                            <div class="relative">
                                <Search
                                    class="absolute left-3 top-2.5 size-4 text-muted-foreground"
                                />

                                <Input
                                    v-model="busqueda"
                                    class="pl-9"
                                    placeholder="Buscar por nombre..."
                                />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                Estatus
                            </label>

                            <select
                                v-model="filtroEstatus"
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="todos">Todos</option>
                                <option value="activa">Activa</option>
                                <option value="eliminada">Eliminada</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="limpiarFiltros"
                        >
                            <RotateCcw class="mr-1 size-4" />
                            Limpiar filtros
                        </Button>
                    </div>
                </div>

                <!-- CONTADOR -->
                <p
                    v-if="hayFiltrosActivos"
                    class="text-sm font-medium"
                >
                    Actividades encontradas:
                    {{ actividadesFiltradas.length }}
                </p>

                <!-- TABLA -->
                <div class="overflow-hidden rounded-lg border">
                    <TablaDatos
                        :columnas="columnas"
                        :filas="actividadesFiltradas"
                        vacio="No se encontraron actividades."
                    >
                        <template #nombre="{ fila }">
                            <span class="font-medium">
                                {{ fila.nombre }}
                            </span>
                        </template>

                        <template #estatus="{ fila }">
                            <span
                                class="inline-flex rounded-full px-3 py-1 text-xs font-medium"
                                :class="
                                    fila.estatus === 'activa'
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-gray-100 text-gray-700'
                                "
                            >
                                {{
                                    fila.estatus === 'activa'
                                        ? 'Activa'
                                        : 'Eliminada'
                                }}
                            </span>
                        </template>

                        <template #acciones="{ fila }">
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        type="button"
                                        :aria-label="`Acciones de ${fila.nombre}`"
                                    >
                                        <Ellipsis class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>

                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @select="abrirEdicion(fila)"
                                    >
                                        <Pencil class="mr-2 size-4" />
                                        Editar
                                    </DropdownMenuItem>

                                    <DropdownMenuItem
                                        @select="abrirCambioEstado(fila)"
                                    >
                                        {{
                                            fila.estatus === 'activa'
                                                ? 'Desactivar'
                                                : 'Reactivar'
                                        }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </template>
                    </TablaDatos>
                </div>

                <!-- EDITAR ACTIVIDAD -->
                <div
                    v-if="actividadEditar"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <h3 class="font-semibold">
                        Editar actividad
                    </h3>

                    <label class="block text-sm">
                        Nombre
                    </label>

                    <Input
    v-model="formEdicion.nombre"
    maxlength="150"
/>

<p
    v-if="formEdicion.errors.nombre"
    class="mt-1 text-sm text-red-600"
>
    {{ formEdicion.errors.nombre }}
</p>

                    <p class="text-xs text-muted-foreground">
                        Los cambios todavía no se guardan.
                    </p>

                    <div class="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            @click="actividadEditarId = null"
                        >
                            Cancelar
                        </Button>


<Button
    type="button"
    :disabled="!habilitarEscritura || formEdicion.processing"
    @click="guardarEdicion"
>
    {{
        formEdicion.processing
            ? 'Guardando...'
            : 'Guardar cambios'
    }}
</Button>

                    </div>
                </div>

                <!-- CAMBIAR ESTATUS -->
                <div
                    v-if="actividadCambiarEstado"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <h3 class="font-semibold">
                        Cambiar estatus:
                        {{ actividadCambiarEstado.nombre }}
                    </h3>

                    <label class="block text-sm">
                        Estatus de la actividad
                    </label>

                    <select
                        v-model="formEstado.estatus"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="activa">Activa</option>
                        <option value="eliminada">Eliminada</option>
                    </select>
                    <p
    v-if="formEstado.errors.estatus"
    class="mt-1 text-sm text-red-600"
>
    {{ formEstado.errors.estatus }}
</p>

                    <p class="text-xs text-muted-foreground">
                        Los cambios todavía no se guardan.
                    </p>

                    <div class="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            @click="actividadEstadoId = null"
                        >
                            Cancelar
                        </Button>


<Button
    type="button"
    :disabled="!habilitarEscritura || formEstado.processing"
    @click="guardarEstado"
>
    {{
        formEstado.processing
            ? 'Guardando...'
            : 'Guardar estatus'
    }}
</Button>

                    </div>
                </div>

            </div>
        </TarjetaSeccion>
    </PaginaBase>
</template>
