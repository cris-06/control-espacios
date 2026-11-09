<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type RequerimientoCatalogo = {
    id: number;
    nombre: string;
};

type RequerimientoAsignado = RequerimientoCatalogo & {
    pivot: {
        cantidad_disponible: number;
    };
};

type Espacio = {
    id: number;
    nombre: string;
    descripcion: string | null;
    ubicacion: string | null;
    foto: string | null;
    capacidad: number | null;
    minimo_alumnos: number | null;
    maximo_alumnos: number | null;
    estatus: 'disponible' | 'mantenimiento' | 'inactivo';
    requerimientos: RequerimientoAsignado[];
};

type RequerimientoCantidad = {
    id: number;
    cantidad_disponible: number;
};

const props = defineProps<{
    espacio: Espacio;
    requerimientos: RequerimientoCatalogo[];
}>();

const emit = defineEmits<{
    cerrar: [];
}>();

const habilitarEscritura = Boolean(usePage().props.auth.permisos.administrar);

const form = useForm({
    nombre: '',
    descripcion: '',
    ubicacion: '',
    capacidad: '' as number | '',
    minimo_alumnos: '' as number | '',
    maximo_alumnos: '' as number | '',
    foto: null as File | null,
    requerimientos: [] as RequerimientoCantidad[],
});

// Inicializar con los datos actuales al seleccionar otro espacio.
watch(
    () => props.espacio,
    (espacio) => {
        form.nombre = espacio.nombre;
        form.descripcion = espacio.descripcion ?? '';
        form.ubicacion = espacio.ubicacion ?? '';
        form.capacidad = espacio.capacidad ?? '';
        form.minimo_alumnos = espacio.minimo_alumnos ?? '';
        form.maximo_alumnos = espacio.maximo_alumnos ?? '';
        form.foto = null;
        form.requerimientos = (espacio.requerimientos ?? []).map((item) => ({
            id: item.id,
            cantidad_disponible: item.pivot.cantidad_disponible,
        }));
        form.clearErrors();
    },
    { immediate: true },
);

// Mostrar tanto los requerimientos activos como los ya asociados al espacio.
// Los que fueron desactivados se señalan para evitar perderlos sin aviso.
const requerimientosVisibles = computed(() => {
    const opciones: RequerimientoCatalogo[] = [...props.requerimientos];

    for (const asignado of props.espacio.requerimientos ?? []) {
        if (!opciones.some((item) => item.id === asignado.id)) {
            opciones.push({ id: asignado.id, nombre: asignado.nombre });
        }
    }

    return opciones.sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));
});

const esRequerimientoActivo = (id: number) =>
    props.requerimientos.some((item) => item.id === id);

const estaAsignado = (id: number) =>
    form.requerimientos.some((item) => item.id === id);

const alternarRequerimiento = (id: number) => {
    const indice = form.requerimientos.findIndex((item) => item.id === id);

    if (indice >= 0) {
        form.requerimientos.splice(indice, 1);
    } else {
        form.requerimientos.push({ id, cantidad_disponible: 1 });
    }
};

const obtenerCantidad = (id: number) =>
    form.requerimientos.find((item) => item.id === id)?.cantidad_disponible ??
    1;

const cambiarCantidad = (id: number, evento: Event) => {
    const valor = (evento.target as HTMLInputElement).value;
    const item = form.requerimientos.find((req) => req.id === id);

    if (item) {
        item.cantidad_disponible = valor === '' ? 0 : Number(valor);
    }
};

const seleccionarFoto = (evento: Event) => {
    const elemento = evento.target as HTMLInputElement;
    const archivo = elemento.files?.[0] ?? null;
    form.clearErrors('foto');

    if (
        archivo &&
        !['image/jpeg', 'image/png', 'image/webp'].includes(archivo.type)
    ) {
        form.setError('foto', 'Selecciona una imagen JPG, PNG o WEBP.');
        form.foto = null;
        elemento.value = '';
        return;
    }

    if (archivo && archivo.size > 3 * 1024 * 1024) {
        form.setError('foto', 'La imagen no puede superar los 3 MB.');
        form.foto = null;
        elemento.value = '';
        return;
    }

    form.foto = archivo;
};

const erroresRequerimientos = computed(() =>
    Object.entries(form.errors)
        .filter(([campo]) => campo.startsWith('requerimientos'))
        .map(([campo, mensaje]) => ({ campo, mensaje })),
);

// Laravel requiere POST con _method=PUT cuando se envían archivos.
// El botón permanece deshabilitado hasta habilitar rutas y permisos.
const guardarCambios = () => {
    if (!habilitarEscritura || form.processing) return;

    form.transform((datos) => ({
        ...datos,
        _method: 'PUT',
    })).post(`/espacios/${props.espacio.id}`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('cerrar'),
    });
};
</script>

<template>
    <form
        class="space-y-4 border-t bg-muted/20 p-4"
        @submit.prevent="guardarCambios"
    >
        <h3 class="font-semibold">Editar espacio: {{ espacio.nombre }}</h3>

        <div class="space-y-2">
            <label class="text-sm font-medium" for="editar-nombre"
                >Nombre</label
            >
            <Input
                id="editar-nombre"
                v-model="form.nombre"
                maxlength="100"
                required
            />
            <p v-if="form.errors.nombre" class="text-sm text-red-600">
                {{ form.errors.nombre }}
            </p>
        </div>

        <div class="space-y-2">
            <label class="text-sm font-medium" for="editar-descripcion"
                >Descripción</label
            >
            <textarea
                id="editar-descripcion"
                v-model="form.descripcion"
                rows="3"
                class="w-full rounded-md border bg-background p-2 text-sm"
            />
            <p v-if="form.errors.descripcion" class="text-sm text-red-600">
                {{ form.errors.descripcion }}
            </p>
        </div>

        <div class="space-y-2">
            <label class="text-sm font-medium" for="editar-ubicacion"
                >Ubicación</label
            >
            <Input
                id="editar-ubicacion"
                v-model="form.ubicacion"
                maxlength="150"
            />
            <p v-if="form.errors.ubicacion" class="text-sm text-red-600">
                {{ form.errors.ubicacion }}
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="space-y-2">
                <label class="text-sm font-medium" for="editar-capacidad"
                    >Capacidad total</label
                >
                <Input
                    id="editar-capacidad"
                    v-model.number="form.capacidad"
                    type="number"
                    min="1"
                    required
                />
                <p v-if="form.errors.capacidad" class="text-sm text-red-600">
                    {{ form.errors.capacidad }}
                </p>
            </div>
            <div class="space-y-2">
                <label class="text-sm font-medium" for="editar-minimo"
                    >Mínimo de alumnos</label
                >
                <Input
                    id="editar-minimo"
                    v-model.number="form.minimo_alumnos"
                    type="number"
                    min="1"
                    required
                />
                <p
                    v-if="form.errors.minimo_alumnos"
                    class="text-sm text-red-600"
                >
                    {{ form.errors.minimo_alumnos }}
                </p>
            </div>
            <div class="space-y-2">
                <label class="text-sm font-medium" for="editar-maximo"
                    >Máximo de alumnos</label
                >
                <Input
                    id="editar-maximo"
                    v-model.number="form.maximo_alumnos"
                    type="number"
                    min="1"
                    required
                />
                <p
                    v-if="form.errors.maximo_alumnos"
                    class="text-sm text-red-600"
                >
                    {{ form.errors.maximo_alumnos }}
                </p>
            </div>
        </div>
        <!-- FOTOGRAFÍA DEL ESPACIO -->
        <div class="space-y-3">
            <label class="block text-sm font-medium">
                Fotografía del espacio (opcional)
            </label>

            <!-- Mostrar fotografía guardada -->
            <div v-if="espacio.foto" class="space-y-2">
                <p class="text-xs text-muted-foreground">Fotografía actual:</p>

                <img
                    :src="`/storage/${espacio.foto}`"
                    :alt="`Fotografía de ${espacio.nombre}`"
                    class="h-48 w-full max-w-md rounded-lg border object-cover"
                />
            </div>

            <!-- Selector de fotografía -->
            <input
                id="editar-foto"
                type="file"
                accept=".jpg,.jpeg,.png,.webp"
                class="sr-only"
                @change="seleccionarFoto"
            />

            <label
                for="editar-foto"
                class="inline-flex cursor-pointer items-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium hover:bg-muted"
            >
                Seleccionar fotografía
            </label>

            <p v-if="form.foto" class="text-xs text-muted-foreground">
                Nueva fotografía: {{ form.foto.name }}
            </p>

            <p v-else class="text-xs text-muted-foreground">
                Si no seleccionas otra fotografía, se conservará la actual.
            </p>

            <p v-if="form.errors.foto" class="text-sm text-red-600">
                {{ form.errors.foto }}
            </p>
        </div>

        <div class="space-y-3 border-t pt-4">
            <h4 class="text-sm font-semibold">
                Requerimientos y cantidades disponibles
            </h4>
            <p class="text-xs text-muted-foreground">
                Marca los requerimientos del espacio y especifica cuántos hay
                disponibles.
            </p>

            <p
                v-if="requerimientosVisibles.length === 0"
                class="text-sm text-muted-foreground"
            >
                No hay requerimientos en el catálogo.
            </p>

            <div
                v-for="requerimiento in requerimientosVisibles"
                :key="requerimiento.id"
                class="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        :checked="estaAsignado(requerimiento.id)"
                        class="size-4"
                        @change="alternarRequerimiento(requerimiento.id)"
                    />
                    <span>{{ requerimiento.nombre }}</span>
                    <span
                        v-if="!esRequerimientoActivo(requerimiento.id)"
                        class="text-xs text-amber-700"
                    >
                        (No activo: desmárcalo antes de guardar)
                    </span>
                </label>

                <div
                    v-if="estaAsignado(requerimiento.id)"
                    class="flex items-center gap-2"
                >
                    <label class="text-xs" :for="`cantidad-${requerimiento.id}`"
                        >Cantidad</label
                    >
                    <input
                        :id="`cantidad-${requerimiento.id}`"
                        type="number"
                        min="1"
                        step="1"
                        :value="obtenerCantidad(requerimiento.id)"
                        class="w-24 rounded-md border bg-background px-2 py-1 text-sm"
                        @input="cambiarCantidad(requerimiento.id, $event)"
                    />
                </div>
            </div>

            <p
                v-for="error in erroresRequerimientos"
                :key="error.campo"
                class="text-sm text-red-600"
            >
                {{ error.mensaje }}
            </p>
        </div>

        <div class="space-y-1 border-t pt-4">
            <p class="text-sm font-medium">
                Estado actual: {{ espacio.estatus }}
            </p>
            <p class="text-xs text-muted-foreground">
                El estado se administra por separado en la opción «Cambiar
                estado».
            </p>
        </div>

        <div class="flex justify-end gap-2">
            <Button type="button" variant="outline" @click="emit('cerrar')">
                Cancelar
            </Button>
            <Button
                type="submit"
                :disabled="!habilitarEscritura || form.processing"
            >
                {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
            </Button>
        </div>
    </form>
</template>
