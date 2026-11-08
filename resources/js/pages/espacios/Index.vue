
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Ellipsis, Eye, RotateCcw, Search } from '@lucide/vue';

import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import TablaDatos from '@/components/TablaDatos.vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import FormularioEspacio from './FormularioEspacio.vue';
import EditarEspacio from './EditarEspacio.vue';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type RequerimientoAsignado = {
    id: number;
    nombre: string;
    pivot: {
        id: number;
        cantidad_disponible: number;
    };
};

// Campos definidos en BD_Reservas_corregida.sql
type Espacio = {
    id: number;
    nombre: string;
    descripcion: string | null;
    ubicacion: string | null;
    foto: string | null;
    capacidad: number | null;
    minimo_alumnos: number | null;
    maximo_alumnos: number | null;
    color_calendario: string;
    estatus: 'disponible' | 'mantenimiento' | 'inactivo';
    requerimientos: RequerimientoAsignado[];
};

const props = defineProps<{
    espacios: Espacio[];
     requerimientos: {
        id: number;
        nombre: string;
    }[];
}>();

const busqueda = ref('');
const filtroEstatus = ref('todos');
const detalleId = ref<number | null>(null);

// Espacio seleccionado para editar
const espacioEditarId = ref<number | null>(null);

const espacioEditar = computed(() =>
    props.espacios.find(
        (espacio) => espacio.id === espacioEditarId.value,
    ) ?? null,
);

const columnas = [
    { clave: 'nombre', titulo: 'Espacio' },
    { clave: 'ubicacion', titulo: 'Ubicación' },
    { clave: 'capacidad', titulo: 'Capacidad' },
    { clave: 'estatus', titulo: 'Estatus' },
    { clave: 'acciones', titulo: 'Acciones' },
];
// Espacio seleccionado para cambiar su estado
const espacioEstadoId = ref<number | null>(null);


type EstadoEspacio = 'disponible' | 'mantenimiento' | 'inactivo';

// Se habilitará cuando estén integrados los permisos
const habilitarEscritura = false;

const formEstado = useForm({
    estatus: 'disponible' as EstadoEspacio,
});


// Obtener el espacio seleccionado
const espacioCambiarEstado = computed(() =>
    props.espacios.find(
        (espacio) => espacio.id === espacioEstadoId.value,
    ) ?? null,
);

// Abrir el apartado para cambiar el estado

const abrirCambioEstado = (espacio: Espacio) => {
    espacioEstadoId.value = espacio.id;
    formEstado.estatus = espacio.estatus;
    formEstado.clearErrors();

    // Cerrar el formulario de edición si está abierto
    espacioEditarId.value = null;
};

// Guardar el nuevo estado del espacio
const guardarEstado = () => {
    if (
        !habilitarEscritura ||
        formEstado.processing ||
        espacioEstadoId.value === null
    ) {
        return;
    }

    formEstado.patch(
        `/espacios/${espacioEstadoId.value}/estatus`,
        {
            preserveScroll: true,
            onSuccess: () => {
                espacioEstadoId.value = null;
            },
        }
    );
};




const espaciosFiltrados = computed(() => {
    const texto = busqueda.value.trim().toLowerCase();

    return props.espacios.filter((espacio) => {
        // Buscar únicamente por nombre
        const coincideNombre = espacio.nombre
            .toLowerCase()
            .includes(texto);

        // Filtrar únicamente por estatus
        const coincideEstado =
            filtroEstatus.value === 'todos' ||
            espacio.estatus === filtroEstatus.value;

        return coincideNombre && coincideEstado;
    });
});



// Mostrar contador únicamente cuando existan filtros activos
const hayFiltrosActivos = computed(() => {
    return (
        busqueda.value.trim() !== '' ||
        filtroEstatus.value !== 'todos'
    );
});


const detalleSeleccionado = computed(() =>
    props.espacios.find(
        (espacio) => espacio.id === detalleId.value,
    ),
);

const limpiarFiltros = () => {
    busqueda.value = '';
    filtroEstatus.value = 'todos';
};

const verDetalles = (id: number) => {
    detalleId.value = detalleId.value === id ? null : id;
};

const estilosEstatus: Record<Espacio['estatus'], string> = {
    disponible: 'bg-green-100 text-green-800',
    mantenimiento: 'bg-amber-100 text-amber-800',
    inactivo: 'bg-gray-100 text-gray-700',
};
</script>

<template>
    <Head title="Administración de espacios" />

    <PaginaBase
        titulo="Administración de espacios"
        descripcion="Consulta y gestiona los espacios físicos de la universidad."
    >
    <!-- Formulario para agregar espacios -->
<FormularioEspacio 
:requerimientos="requerimientos" 
:habilitar-guardado="false"
/>

    <TarjetaSeccion
    titulo="Espacios registrados"
>
  
<!-- BUSCADOR Y FILTROS -->
<div class="grid gap-3 border-b p-4 md:grid-cols-2">

    <!-- Buscar por nombre o ubicación -->
    <div>
        <label class="mb-1 block text-sm font-medium">
            Buscar espacio
        </label>

        <div class="relative">
            <Search
                class="absolute top-2.5 left-3 size-4 text-muted-foreground"
            />

            <Input
                v-model="busqueda"
                class="pl-9"
                placeholder="Buscar por nombre..."
            />
        </div>
    </div>

    <!-- Filtro de estados -->
    <div>
        <label class="mb-1 block text-sm font-medium">
            Estados
        </label>

        <select
            v-model="filtroEstatus"
            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        >
            <option value="todos">Todos</option>
            <option value="disponible">Disponible</option>
            <option value="mantenimiento">Mantenimiento</option>
            <option value="inactivo">Inactivo</option>
        </select>
    </div>

    <!-- Limpiar filtros -->
    <div class="flex justify-end md:col-span-2">
        <Button
            variant="outline"
            size="sm"
            @click="limpiarFiltros"
        >
            <RotateCcw class="size-4" />
            Limpiar filtros
        </Button>
    </div>
</div>

<!-- CONTADOR DE RESULTADOS -->
<div
    v-if="hayFiltrosActivos"
    class="px-4 pt-3 text-sm font-medium"
>
    Espacios encontrados: {{ espaciosFiltrados.length }}
</div>

            <!-- TABLA -->
            <TablaDatos
                :columnas="columnas"
                :filas="espaciosFiltrados"
                vacio="No se encontraron espacios registrados."
            >
                <template #nombre="{ fila }">
                    <div class="flex items-center gap-3">
                        <span
                            class="size-3 shrink-0 rounded-full"
                            :style="{
                                backgroundColor: fila.color_calendario,
                            }"
                        ></span>

                        <span class="font-medium">
                            {{ fila.nombre }}
                        </span>
                    </div>
                </template>

                <template #ubicacion="{ fila }">
                    {{ fila.ubicacion ?? 'Sin ubicación' }}
                </template>

                <template #capacidad="{ fila }">
                    <span>
                        {{
                            fila.capacidad !== null
                                ? `${fila.capacidad} personas`
                                : 'Sin definir'
                        }}
                    </span>
                </template>

                <template #estatus="{ fila }">
                    <span
                        class="inline-flex rounded-full px-3 py-1 text-xs font-medium capitalize"
                        :class="estilosEstatus[fila.estatus as Espacio['estatus']]"
                    >
                        {{ fila.estatus }}
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
    <!-- Ver detalles -->
    <DropdownMenuItem
        @select="verDetalles(fila.id)"
    >
        <Eye class="mr-2 size-4" />
        Ver detalles
    </DropdownMenuItem>

    <!-- Editar espacio -->
    <DropdownMenuItem
        @select="espacioEditarId = fila.id"
    >
        Editar espacio
    </DropdownMenuItem>

    <!-- Cambiar estado -->
    <DropdownMenuItem
        @select="abrirCambioEstado(fila)"
    >
        Cambiar estado
    </DropdownMenuItem>
</DropdownMenuContent>

                    </DropdownMenu>
                </template>
            </TablaDatos>
<EditarEspacio
    v-if="espacioEditar"
    :espacio="espacioEditar"
    :requerimientos="requerimientos"
    @cerrar="espacioEditarId = null"
/>

<!-- Cambiar estado del espacio -->
<div
    v-if="espacioCambiarEstado"
    class="mt-4 space-y-4 rounded-lg border p-4"
>
    <h3 class="text-base font-semibold">
        Cambiar estado:
        {{ espacioCambiarEstado.nombre }}
    </h3>

    <div class="space-y-2">
        <label class="text-sm font-medium">
            Estado del espacio
        </label>

        <select
            v-model="formEstado.estatus"
            class="w-full rounded-md border bg-background p-2 text-sm"
        >
            <option value="disponible">
                Disponible
            </option>

            <option value="mantenimiento">
                Mantenimiento
            </option>

            <option value="inactivo">
                Inactivo
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
        Los cambios de estado todavía no se guardan.
    </p>

    <div class="flex justify-end gap-2">
        <Button
            variant="outline"
            @click="espacioEstadoId = null"
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
            : 'Guardar estado'
    }}
</Button>

    </div>
</div>

            <!-- DETALLE DEL ESPACIO SELECCIONADO -->
            <div
                v-if="detalleSeleccionado"
                class="border-t bg-muted/30 p-4"
            >
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-semibold">
                        Detalles: {{ detalleSeleccionado.nombre }}
                    </h3>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="detalleId = null"
                    >
                        Cerrar
                    </Button>
                </div>

                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <p>
                        <strong>Descripción:</strong>
                        {{ detalleSeleccionado.descripcion ?? 'Sin descripción' }}
                    </p>
                    <p>
                        <strong>Ubicación:</strong>
                        {{ detalleSeleccionado.ubicacion ?? 'Sin ubicación' }}
                    </p>
                    <p>
                        <strong>Capacidad:</strong>
                        {{ detalleSeleccionado.capacidad ?? 'Sin definir' }}
                    </p>
                    <p>
                        <strong>Mínimo de alumnos:</strong>
                        {{ detalleSeleccionado.minimo_alumnos ?? 'Sin definir' }}
                    </p>
                    <p>
                        <strong>Máximo de alumnos:</strong>
                        {{ detalleSeleccionado.maximo_alumnos ?? 'Sin definir' }}
                    </p>
                    
                    <p>
                        <strong>Estatus:</strong>
                        {{ detalleSeleccionado.estatus }}
                    </p>
                </div>

                <!-- REQUERIMIENTOS DEL ESPACIO -->
                <div class="mt-5 border-t pt-4">
                    <h4 class="mb-3 text-sm font-semibold">
                        Requerimientos disponibles
                    </h4>

                    <div
                        v-if="detalleSeleccionado.requerimientos?.length"
                        class="overflow-hidden rounded-lg border"
                    >
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-4 py-3 text-left">
                                        Requerimiento
                                    </th>
                                    <th class="px-4 py-3 text-right">
                                        Cantidad disponible
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr
                                    v-for="requerimiento in detalleSeleccionado.requerimientos"
                                    :key="requerimiento.id"
                                    class="border-t"
                                >
                                    <td class="px-4 py-3">
                                        {{ requerimiento.nombre }}
                                    </td>

                                    <td class="px-4 py-3 text-right">
                                        {{
                                            requerimiento.pivot
                                                .cantidad_disponible
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p
                        v-else
                        class="text-sm text-muted-foreground"
                    >
                        Este espacio no tiene requerimientos asignados.
                    </p>
                </div>

            </div>
        </TarjetaSeccion>
        </PaginaBase>
        </template>