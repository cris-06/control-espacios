
<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Espacio = {
    id: number;
    nombre: string;
    descripcion: string | null;
    ubicacion: string | null;
    capacidad: number | null;
    minimo_alumnos: number | null;
    maximo_alumnos: number | null;
    estatus: 'disponible' | 'mantenimiento' | 'inactivo';
};

const props = defineProps<{
    espacio: Espacio;
}>();

const emit = defineEmits<{
    cerrar: [];
}>();

const form = reactive({
    nombre: '',
    descripcion: '',
    ubicacion: '',
    capacidad: '' as number | '',
    minimo_alumnos: '' as number | '',
    maximo_alumnos: '' as number | '',
    estatus: 'disponible' as Espacio['estatus'],
});

watch(
    () => props.espacio,
    (espacio) => {
        form.nombre = espacio.nombre;
        form.descripcion = espacio.descripcion ?? '';
        form.ubicacion = espacio.ubicacion ?? '';
        form.capacidad = espacio.capacidad ?? '';
        form.minimo_alumnos = espacio.minimo_alumnos ?? '';
        form.maximo_alumnos = espacio.maximo_alumnos ?? '';
        form.estatus = espacio.estatus;
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-4 border-t bg-muted/20 p-4">
        <h3 class="font-semibold">
            Editar espacio: {{ espacio.nombre }}
        </h3>

        <div class="space-y-2">
            <label class="text-sm">Nombre</label>
            <Input v-model="form.nombre" />
        </div>

        <div class="space-y-2">
            <label class="text-sm">Descripción</label>
            <textarea
                v-model="form.descripcion"
                rows="3"
                class="w-full rounded-md border bg-background p-2 text-sm"
            />
        </div>

        <div class="space-y-2">
            <label class="text-sm">Ubicación</label>
            <Input v-model="form.ubicacion" />
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="text-sm">Capacidad total</label>
                <Input v-model.number="form.capacidad" type="number" min="1" />
            </div>

            <div>
                <label class="text-sm">Mínimo de alumnos</label>
                <Input v-model.number="form.minimo_alumnos" type="number" min="1" />
            </div>

            <div>
                <label class="text-sm">Máximo de alumnos</label>
                <Input v-model.number="form.maximo_alumnos" type="number" min="1" />
            </div>
        </div>

        <div class="space-y-2">
            <label class="text-sm font-medium">
                Estado del espacio
            </label>

            <select
                v-model="form.estatus"
                class="w-full rounded-md border bg-background p-2 text-sm"
            >
                <option value="disponible">Disponible</option>
                <option value="mantenimiento">Mantenimiento</option>
                <option value="inactivo">Inactivo</option>
            </select>
        </div>

        <p class="text-xs text-muted-foreground">
            Vista previa de edición. Los cambios todavía no se guardan.
        </p>

        <div class="flex justify-end gap-2">
            <Button variant="outline" @click="emit('cerrar')">
                Cancelar
            </Button>

            <Button disabled>
                Guardar cambios
            </Button>
        </div>
    </div>
</template>
