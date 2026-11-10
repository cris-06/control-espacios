<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Plus, Trash2 } from '@lucide/vue';

import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import TablaDatos from '@/components/TablaDatos.vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

type Actividad = {
    id: number;
    nombre: string;
    estatus: 'activa' | 'eliminada';
};

const props = defineProps<{
    actividades: Actividad[];
}>();

const page = usePage();

const habilitarEscritura = computed(() =>
    Boolean(page.props.auth.permisos.administrar),
);

// Mostrar únicamente las actividades activas
const actividadesActivas = computed(() =>
    props.actividades.filter((item) => item.estatus === 'activa'),
);

// Columnas
const columnas = [
    { clave: 'nombre', titulo: 'Actividad' },
    { clave: 'eliminar', titulo: '' },
];

// Formulario para agregar
const formNuevo = useForm({
    nombre: '',
});

const guardarActividad = () => {
    if (!habilitarEscritura.value || formNuevo.processing) {
        return;
    }

    formNuevo.post('/actividades', {
        preserveScroll: true,
        onSuccess: () => formNuevo.reset(),
    });
};

// Formulario para eliminar
const formEliminar = useForm({
    estatus: 'eliminada',
});

const eliminarActividad = (item: Actividad) => {
    if (!habilitarEscritura.value || formEliminar.processing) {
        return;
    }

    if (!window.confirm(`¿Deseas eliminar la actividad "${item.nombre}"?`)) {
        return;
    }

    formEliminar.patch(`/actividades/${item.id}/estatus`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Actividades" />

    <PaginaBase
        titulo="Catálogo de actividades"
        descripcion="Administra las actividades que se pueden realizar en los espacios universitarios."
    >
        <TarjetaSeccion titulo="Administración de actividades">
            <div class="space-y-5 p-4">
                <!-- AGREGAR ACTIVIDAD -->
                <form class="space-y-3" @submit.prevent="guardarActividad">
                    <h3 class="text-sm font-semibold">Agregar actividad</h3>

                    <label
                        for="nueva-actividad"
                        class="block text-sm font-medium"
                    >
                        Nombre de la actividad
                    </label>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <div class="flex-1">
                            <Input
                                id="nueva-actividad"
                                v-model="formNuevo.nombre"
                                placeholder="Ej. Conferencia, taller, clase..."
                                maxlength="150"
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

                <!-- TABLA DE ACTIVIDADES -->
                <div class="border-t pt-4">
                    <h3 class="mb-3 text-sm font-semibold">
                        Actividades registradas
                    </h3>

                    <div class="overflow-hidden rounded-lg border">
                        <TablaDatos
                            :columnas="columnas"
                            :filas="actividadesActivas"
                            vacio="No hay actividades registradas."
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
                                    @click="eliminarActividad(fila)"
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
