
<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';

// Tipos de requerimientos de la base de datos
type Requerimiento = {
    id: number;
    nombre: string;
};

// Propiedades recibidas desde Index.vue
const props = withDefaults(
    defineProps<{
        requerimientos: Requerimiento[];
        habilitarGuardado?: boolean;
    }>(),
    {
        habilitarGuardado: false,
    },
);

// Requerimientos seleccionados y cantidades
const seleccionados = reactive<Record<number, boolean>>({});
const cantidades = reactive<Record<number, number>>({});

// Referencia al campo de fotografía
const fotoInput = ref<HTMLInputElement | null>(null);

// Formulario de Inertia
const form = useForm({
    nombre: '',
    descripcion: '',
    ubicacion: '',
    capacidad: '' as number | '',
    minimo_alumnos: '' as number | '',
    maximo_alumnos: '' as number | '',
    foto: null as File | null,
    requerimientos: [] as {
        id: number;
        cantidad_disponible: number;
    }[],
});

// Capturar fotografía
const seleccionarFoto = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const archivo = input.files?.[0] ?? null;

    form.clearErrors('foto');

    if (archivo) {
        const tiposPermitidos = [
            'image/jpeg',
            'image/png',
            'image/webp',
        ];

        if (!tiposPermitidos.includes(archivo.type)) {
            form.setError('foto', 'Selecciona una imagen JPG, PNG o WEBP.');
            input.value = '';
            form.foto = null;
            return;
        }

        if (archivo.size > 3 * 1024 * 1024) {
            form.setError('foto', 'La fotografía no debe superar los 3 MB.');
            input.value = '';
            form.foto = null;
            return;
        }
    }

    form.foto = archivo;
};

// Preparar los requerimientos para Laravel
const prepararRequerimientos = () => {
    form.requerimientos = Object.entries(seleccionados)
        .filter(([, seleccionado]) => seleccionado)
        .map(([id]) => ({
            id: Number(id),
            cantidad_disponible: Number(
                cantidades[Number(id)] || 0,
            ),
        }));
};

// Limpiar formulario
const limpiarFormulario = () => {
    form.reset();
    form.clearErrors();

    Object.keys(seleccionados).forEach((id) => {
        delete seleccionados[Number(id)];
    });

    Object.keys(cantidades).forEach((id) => {
        delete cantidades[Number(id)];
    });

    if (fotoInput.value) {
        fotoInput.value.value = '';
    }
};

// Guardar espacio
const guardarEspacio = () => {
    // No permitir guardar mientras no estén listos los permisos
    if (!props.habilitarGuardado || form.processing) {
        return;
    }

    prepararRequerimientos();
    form.clearErrors();

    // Validar cantidades seleccionadas
    const cantidadesInvalidas = form.requerimientos.some(
        (item) =>
            !Number.isInteger(item.cantidad_disponible) ||
            item.cantidad_disponible < 1,
    );

    if (cantidadesInvalidas) {
        form.setError(
            'requerimientos',
            'Los requerimientos seleccionados deben tener una cantidad mayor que cero.',
        );
        return;
    }

    form.post('/espacios', {
        forceFormData: true,
        preserveScroll: true,

        onSuccess: () => {
            limpiarFormulario();
        },
    });
};
</script>

<template>
    <TarjetaSeccion titulo="Agregar espacio">
        <form
            class="space-y-5 p-4"
            @submit.prevent="guardarEspacio"
        >
            <!-- INFORMACIÓN GENERAL -->
            <div class="space-y-3">
                <h3 class="text-sm font-semibold">
                    Información general
                </h3>

                <!-- Nombre -->
                <div>
                    <label
                        for="nombre"
                        class="mb-1 block text-sm font-medium"
                    >
                        Nombre del espacio
                    </label>

                    <Input
                        id="nombre"
                        v-model="form.nombre"
                        name="nombre"
                        placeholder="Ej. Aula de cómputo"
                        maxlength="100"
                        required
                    />

                    <p
                        v-if="form.errors.nombre"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ form.errors.nombre }}
                    </p>
                </div>

                <!-- Descripción -->
                <div>
                    <label
                        for="descripcion"
                        class="mb-1 block text-sm font-medium"
                    >
                        Descripción
                    </label>

                    <textarea
                        id="descripcion"
                        v-model="form.descripcion"
                        name="descripcion"
                        rows="3"
                        placeholder="Descripción del espacio"
                        class="w-full rounded-md border border-input bg-background p-2 text-sm"
                    ></textarea>

                    <p
                        v-if="form.errors.descripcion"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ form.errors.descripcion }}
                    </p>
                </div>

                <!-- Ubicación -->
                <div>
                    <label
                        for="ubicacion"
                        class="mb-1 block text-sm font-medium"
                    >
                        Ubicación
                    </label>

                    <Input
                        id="ubicacion"
                        v-model="form.ubicacion"
                        name="ubicacion"
                        maxlength="150"
                        placeholder="Ej. Edificio A"
                    />

                    <p
                        v-if="form.errors.ubicacion"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ form.errors.ubicacion }}
                    </p>
                </div>
            </div>

            <!-- CAPACIDAD -->
            <div class="space-y-3 border-t pt-4">
                <h3 class="text-sm font-semibold">
                    Capacidad del espacio
                </h3>

                <div class="grid gap-3 sm:grid-cols-3">
                    <!-- Capacidad total -->
                    <div>
                        <label
                            for="capacidad"
                            class="mb-1 block text-sm font-medium"
                        >
                            Capacidad total
                        </label>

                        <Input
                            id="capacidad"
                            v-model.number="form.capacidad"
                            name="capacidad"
                            type="number"
                            min="1"
                            placeholder="Ej. 40"
                            required
                        />

                        <p
                            v-if="form.errors.capacidad"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.capacidad }}
                        </p>
                    </div>

                    <!-- Mínimo de alumnos -->
                    <div>
                        <label
                            for="minimo"
                            class="mb-1 block text-sm font-medium"
                        >
                            Mínimo de alumnos
                        </label>

                        <Input
                            id="minimo"
                            v-model.number="form.minimo_alumnos"
                            name="minimo_alumnos"
                            type="number"
                            min="1"
                            placeholder="Ej. 1"
                            required
                        />

                        <p
                            v-if="form.errors.minimo_alumnos"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.minimo_alumnos }}
                        </p>
                    </div>

                    <!-- Máximo de alumnos -->
                    <div>
                        <label
                            for="maximo"
                            class="mb-1 block text-sm font-medium"
                        >
                            Máximo de alumnos
                        </label>

                        <Input
                            id="maximo"
                            v-model.number="form.maximo_alumnos"
                            name="maximo_alumnos"
                            type="number"
                            min="1"
                            placeholder="Ej. 40"
                            required
                        />

                        <p
                            v-if="form.errors.maximo_alumnos"
                            class="mt-1 text-sm text-red-600"
                        >
                            {{ form.errors.maximo_alumnos }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- REQUERIMIENTOS -->
            <div class="space-y-3 border-t pt-4">
                <h3 class="text-sm font-semibold">
                    Requerimientos disponibles en el espacio
                </h3>

                <div
                    v-if="requerimientos.length"
                    class="grid gap-3 md:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="requerimiento in requerimientos"
                        :key="requerimiento.id"
                        class="flex items-center gap-3 rounded-lg border p-3"
                    >
                        <label
                            class="flex flex-1 items-center gap-2 text-sm"
                        >
                            <input
                                v-model="seleccionados[requerimiento.id]"
                                type="checkbox"
                            />

                            {{ requerimiento.nombre }}
                        </label>

                        <Input
                            v-model.number="cantidades[requerimiento.id]"
                            type="number"
                            min="1"
                            placeholder="Cantidad"
                            class="w-24"
                            :disabled="!seleccionados[requerimiento.id]"
                        />
                    </div>
                </div>

                <p
                    v-else
                    class="text-sm text-muted-foreground"
                >
                    Todavía no hay requerimientos activos registrados.
                </p>

                <p
                    v-if="form.errors.requerimientos"
                    class="text-sm text-red-600"
                >
                    {{ form.errors.requerimientos }}
                </p>

                <p class="text-xs text-muted-foreground">
                    Selecciona los requerimientos disponibles
                    e indica la cantidad de cada uno.
                </p>
            </div>

            <!-- FOTOGRAFÍA -->
            <div class="space-y-2 border-t pt-4">
                <label
                    for="foto"
                    class="block text-sm font-medium"
                >
                    Fotografía del espacio
                </label>

                <input
                    id="foto"
                    ref="fotoInput"
                    name="foto"
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp"
                    @change="seleccionarFoto"
                    class="w-full rounded-md border border-input p-2 text-sm"
                />

                <p class="text-xs text-muted-foreground">
                    JPG, PNG o WEBP — máximo 3 MB.
                </p>

                <p
                    v-if="form.errors.foto"
                    class="text-sm text-red-600"
                >
                    {{ form.errors.foto }}
                </p>
            </div>

            <!-- BOTÓN GUARDAR -->
            <div class="flex justify-end border-t pt-4">
                <Button
                    type="submit"
                    :disabled="!habilitarGuardado || form.processing"
                >
                    {{
                        form.processing
                            ? 'Guardando...'
                            : 'Guardar espacio'
                    }}
                </Button>
            </div>

            <!-- AVISO TEMPORAL -->
            <p
                v-if="!habilitarGuardado"
                class="text-right text-xs text-muted-foreground"
            >
                El guardado se habilitará cuando se integren
                los permisos de administrador.
            </p>
        </form>
    </TarjetaSeccion>
</template>
