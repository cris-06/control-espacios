<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    Ellipsis,
    Pencil,
    RotateCcw,
    Search,
    Plus,
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

// Estructura de la tabla requerimientos
type Requerimiento = {
    id: number;
    nombre: string;
    estatus: 'activo' | 'eliminado';
};

// Datos enviados desde Laravel
const props = defineProps<{
    requerimientos: Requerimiento[];
}>();

// Formulario para agregar

const habilitarEscritura = false;

const formNuevo = useForm({
    nombre: '',
});

// Registrar requerimiento en Laravel
const guardarRequerimiento = () => {
    if (!habilitarEscritura || formNuevo.processing) {
        return;
    }

    formNuevo.post('/requerimientos', {
        preserveScroll: true,

        onSuccess: () => {
            formNuevo.reset();
        },
    });
};

// Buscador y filtros
const busqueda = ref('');
const filtroEstatus = ref('todos');


 // Edición de requerimientos
const requerimientoEditarId = ref<number | null>(null);

const formEdicion = useForm({
    nombre: '',
});


// Cambio de estatus
const requerimientoEstadoId = ref<number | null>(null);
const formEstado = useForm({
    estatus: 'activo' as 'activo' | 'eliminado',
});
// Columnas de la tabla
const columnas = [
    { clave: 'nombre', titulo: 'Requerimiento' },
    { clave: 'estatus', titulo: 'Estatus' },
    { clave: 'acciones', titulo: 'Acciones' },
];

// Filtrar requerimientos
const requerimientosFiltrados = computed(() => {
    const texto = busqueda.value.trim().toLowerCase();

    return props.requerimientos.filter((requerimiento) => {
        const coincideNombre = requerimiento.nombre
            .toLowerCase()
            .includes(texto);

        const coincideEstado =
            filtroEstatus.value === 'todos' ||
            requerimiento.estatus === filtroEstatus.value;

        return coincideNombre && coincideEstado;
    });
});

// Mostrar cantidad cuando se utilizan filtros
const hayFiltrosActivos = computed(() =>
    busqueda.value.trim() !== '' ||
    filtroEstatus.value !== 'todos'
);

// Limpiar filtros
const limpiarFiltros = () => {
    busqueda.value = '';
    filtroEstatus.value = 'todos';
};

// Requerimiento seleccionado para editar
const requerimientoEditar = computed(() =>
    props.requerimientos.find(
        (item) => item.id === requerimientoEditarId.value
    ) ?? null
);

// Abrir formulario de edición

const abrirEdicion = (requerimiento: Requerimiento) => {
    requerimientoEditarId.value = requerimiento.id;

    formEdicion.nombre = requerimiento.nombre;
    formEdicion.clearErrors();

    requerimientoEstadoId.value = null;
};

// Actualizar requerimiento en Laravel
const guardarEdicion = () => {
    if (
        !habilitarEscritura ||
        formEdicion.processing ||
        requerimientoEditarId.value === null
    ) {
        return;
    }

    formEdicion.put(
        `/requerimientos/${requerimientoEditarId.value}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                requerimientoEditarId.value = null;
            },
        }
    );
};
// Requerimiento seleccionado para cambiar estatus
const requerimientoCambiarEstado = computed(() =>
    props.requerimientos.find(
        (item) => item.id === requerimientoEstadoId.value
    ) ?? null
);

// Abrir selector de estatus

const abrirCambioEstado = (requerimiento: Requerimiento) => {
    requerimientoEstadoId.value = requerimiento.id;
    formEstado.estatus = requerimiento.estatus;
    formEstado.clearErrors();

    requerimientoEditarId.value = null;
};

// Guardar el cambio de estatus en Laravel
const guardarEstado = () => {
    if (
        !habilitarEscritura ||
        formEstado.processing ||
        requerimientoEstadoId.value === null
    ) {
        return;
    }

    formEstado.patch(
        `/requerimientos/${requerimientoEstadoId.value}/estatus`,
        {
            preserveScroll: true,
            onSuccess: () => {
                requerimientoEstadoId.value = null;
            },
        }
    );
};

</script>

<template>
    <Head title="Requerimientos" />

    <PaginaBase
        titulo="Catálogo de requerimientos"
        descripcion="Administra los equipos y materiales disponibles para los espacios."
    >
        <TarjetaSeccion titulo="Administración de requerimientos">
            <div class="space-y-5 p-4">

                <!-- AGREGAR REQUERIMIENTO -->
                <form
                    class="space-y-3"
                    @submit.prevent="guardarRequerimiento"
                >
                    <h3 class="text-sm font-semibold">
                        Agregar requerimiento
                    </h3>

                    <div>
                        <label
                            for="nuevo-requerimiento"
                            class="mb-1 block text-sm font-medium"
                        >
                            Nombre del requerimiento
                        </label>

                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                            <!-- Campo para el nombre -->
                            <div class="min-w-0 flex-1">
                                <Input
                                    id="nuevo-requerimiento"
                                    v-model="formNuevo.nombre"
                                    placeholder="Ej. Proyector, mesas, sillas..."
                                    maxlength="100"
                                    class="w-full"
                                    required
                                />

                                <!-- Mensaje de validación -->
                                <p
                                    v-if="formNuevo.errors.nombre"
                                    class="mt-1 text-sm text-red-600"
                                >
                                    {{ formNuevo.errors.nombre }}
                                </p>
                            </div>

                            <!-- Botón Agregar -->
                            <Button
                                type="submit"
                                :disabled="!habilitarEscritura || formNuevo.processing"
                            >
                                <Plus class="mr-1 size-4" />
                                {{ formNuevo.processing ? 'Guardando...' : 'Agregar' }}
                            </Button>
                        </div>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        El registro se habilitará cuando se integren
                        los permisos de administrador.
                    </p>
                </form>

                <!-- BUSCADOR Y FILTROS -->
                <div class="space-y-3 border-t pt-4">
                    <h3 class="text-sm font-semibold">
                        Requerimientos registrados
                    </h3>

                    <div class="grid gap-3 md:grid-cols-2">

                        <!-- Buscar por nombre -->
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium"
                            >
                                Buscar requerimiento
                            </label>

                            <div class="relative">
                                <Search
                                    class="absolute left-3 top-2.5 size-4 text-muted-foreground"
                                />

                                <Input
                                    v-model="busqueda"
                                    placeholder="Buscar por nombre..."
                                    class="pl-9"
                                />
                            </div>
                        </div>

                        <!-- Filtro por estatus -->
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium"
                            >
                                Estatus
                            </label>

                            <select
                                v-model="filtroEstatus"
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="todos">
                                    Todos
                                </option>

                                <option value="activo">
                                    Activo
                                </option>

                                <option value="eliminado">
                                    Eliminado
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Limpiar filtros -->
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
                    Requerimientos encontrados:
                    {{ requerimientosFiltrados.length }}
                </p>

                <!-- TABLA DE REQUERIMIENTOS -->
                <div class="overflow-hidden rounded-lg border">
                    <TablaDatos
                        :columnas="columnas"
                        :filas="requerimientosFiltrados"
                        vacio="No se encontraron requerimientos."
                    >
                        <!-- Nombre -->
                        <template #nombre="{ fila }">
                            <span class="font-medium">
                                {{ fila.nombre }}
                            </span>
                        </template>

                        <!-- Estatus -->
                        <template #estatus="{ fila }">
                            <span
                                class="inline-flex rounded-full px-3 py-1 text-xs font-medium"
                                :class="
                                    fila.estatus === 'activo'
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-gray-100 text-gray-700'
                                "
                            >
                                {{
                                    fila.estatus === 'activo'
                                        ? 'Activo'
                                        : 'Eliminado'
                                }}
                            </span>
                        </template>

                        <!-- Acciones -->
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
                                            fila.estatus === 'activo'
                                                ? 'Desactivar'
                                                : 'Reactivar'
                                        }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </template>
                    </TablaDatos>
                </div>

                <!-- EDITAR REQUERIMIENTO -->
                <div
                    v-if="requerimientoEditar"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <h3 class="font-semibold">
                        Editar requerimiento
                    </h3>

                    <div>
                        <label class="mb-1 block text-sm">
                            Nombre
                        </label>

                        <Input
    v-model="formEdicion.nombre"
    maxlength="100"
/>

<p
    v-if="formEdicion.errors.nombre"
    class="mt-1 text-sm text-red-600"
>
    {{ formEdicion.errors.nombre }}
</p>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Los cambios todavía no se guardan.
                    </p>

                    <div class="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            @click="requerimientoEditarId = null"
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
                    v-if="requerimientoCambiarEstado"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <h3 class="font-semibold">
                        Cambiar estatus:
                        {{ requerimientoCambiarEstado.nombre }}
                    </h3>

                    <div>
                        <label class="mb-1 block text-sm">
                            Estatus
                        </label>

                        <select
                            v-model="formEstado.estatus"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="activo">
                                Activo
                            </option>

                            <option value="eliminado">
                                Eliminado
                            </option>
                        </select>
                        <p
    v-if="formEstado.errors.estatus"
    class="mt-1 text-sm text-red-600"
>
    {{ formEstado.errors.estatus }}
</p>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Los cambios todavía no se guardan.
                    </p>

                    <div class="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            @click="requerimientoEstadoId = null"
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
