<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Plus, Trash2 } from '@lucide/vue';

import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import TablaDatos from '@/components/TablaDatos.vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

type Requerimiento = {
    id: number;
    nombre: string;
    estatus: 'activo' | 'eliminado';
};

const props = defineProps<{
    requerimientos: Requerimiento[];
}>();

const page = usePage();

const habilitarEscritura = computed(() =>
    Boolean(page.props.auth.permisos.administrar),
);

// Mostrar únicamente los requerimientos activos
const requerimientosActivos = computed(() =>
    props.requerimientos.filter((item) => item.estatus === 'activo'),
);

// Columnas de la tabla
const columnas = [
    { clave: 'nombre', titulo: 'Requerimiento' },
    { clave: 'eliminar', titulo: '' },
];

// Formulario para agregar
const formNuevo = useForm({
    nombre: '',
});

const guardarRequerimiento = () => {
    if (!habilitarEscritura.value || formNuevo.processing) {
        return;
    }

    formNuevo.post('/requerimientos', {
        preserveScroll: true,
        onSuccess: () => formNuevo.reset(),
    });
};

// Formulario para eliminar
const formEliminar = useForm({
    estatus: 'eliminado',
});

const eliminarRequerimiento = (item: Requerimiento) => {
    if (!habilitarEscritura.value || formEliminar.processing) {
        return;
    }

    if (
        !window.confirm(`¿Deseas eliminar el requerimiento "${item.nombre}"?`)
    ) {
        return;
    }

    formEliminar.patch(`/requerimientos/${item.id}/estatus`, {
        preserveScroll: true,
    });
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
                <form class="space-y-3" @submit.prevent="guardarRequerimiento">
                    <h3 class="text-sm font-semibold">Agregar requerimiento</h3>

                    <label
                        for="nuevo-requerimiento"
                        class="block text-sm font-medium"
                    >
                        Nombre del requerimiento
                    </label>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <div class="flex-1">
                            <Input
                                id="nuevo-requerimiento"
                                v-model="formNuevo.nombre"
                                placeholder="Ej. Proyector, mesas, sillas..."
                                maxlength="100"
                                required
                            />

                            <p
                                v-if="formNuevo.errors.nombre"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ formNuevo.errors.nombre }}
                            </p>
                        </div>

                        <Button
                            type="submit"
                            :disabled="
                                !habilitarEscritura || formNuevo.processing
                            "
                        >
                            <Plus class="mr-1 size-4" />
                            {{
                                formNuevo.processing
                                    ? 'Guardando...'
                                    : 'Agregar'
                            }}
                        </Button>
                    </div>
                </form>

                <!-- TABLA DE REQUERIMIENTOS -->
                <div class="border-t pt-4">
                    <h3 class="mb-3 text-sm font-semibold">
                        Requerimientos registrados
                    </h3>

                    <div class="overflow-hidden rounded-lg border">
                        <TablaDatos
                            :columnas="columnas"
                            :filas="requerimientosActivos"
                            vacio="No hay requerimientos registrados."
                        >
                            <template #nombre="{ fila }">
                                <span class="font-medium">
                                    {{ fila.nombre }}
                                </span>
                            </template>

                            <template #eliminar="{ fila }">
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    :disabled="
                                        !habilitarEscritura ||
                                        formEliminar.processing
                                    "
                                    @click="eliminarRequerimiento(fila)"
                                >
                                    <Trash2 class="mr-1 size-4" />
                                    Eliminar
                                </Button>
                            </template>
                        </TablaDatos>
                    </div>
                </div>
            </div>
        </TarjetaSeccion>
    </PaginaBase>
</template>
