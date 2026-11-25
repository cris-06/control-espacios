<script setup lang="ts">
import { ChevronDown, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { Opcion } from '@/types';

/**
 * «Escribe o selecciona…»: campo que filtra una lista mientras se escribe y solo acepta una de
 * sus opciones (por ejemplo, usuarios autorizados). No admite texto libre: al salir del campo
 * sin elegir, vuelve a mostrar la opción elegida. El valor es el id como texto (o null).
 */
const props = withDefaults(
    defineProps<{
        id: string;
        opciones: Opcion[];
        placeholder?: string;
        disabled?: boolean;
        // Texto cuando no hay opciones (p. ej. «Primero elige la licenciatura»).
        sinOpciones?: string;
    }>(),
    {
        placeholder: 'Escribe o selecciona…',
        disabled: false,
        sinOpciones: 'No hay opciones disponibles.',
    },
);

const valor = defineModel<string | null>({ required: true });

const nombreDe = (id: string | null) =>
    props.opciones.find((o) => String(o.id) === id)?.nombre ?? '';

const texto = ref(nombreDe(valor.value));
const abierto = ref(false);
const activo = ref(0);

// Si el valor cambia desde fuera (otra licenciatura, datos guardados), se actualiza el texto.
watch(
    () => [valor.value, props.opciones] as const,
    () => {
        if (!abierto.value) {
            texto.value = nombreDe(valor.value);
        }
    },
);

const normalizar = (s: string) =>
    s
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

const filtradas = computed(() => {
    const buscado = normalizar(texto.value.trim());

    // Recién abierto con la opción elegida a la vista: se ofrecen todas.
    if (buscado === '' || texto.value === nombreDe(valor.value)) {
        return props.opciones;
    }

    return props.opciones.filter((o) => normalizar(o.nombre).includes(buscado));
});

const listaId = computed(() => `${props.id}-opciones`);

const abrir = () => {
    if (props.disabled) {
        return;
    }

    abierto.value = true;
    activo.value = Math.max(
        0,
        filtradas.value.findIndex((o) => String(o.id) === valor.value),
    );
};

const elegir = (opcion: Opcion) => {
    valor.value = String(opcion.id);
    texto.value = opcion.nombre;
    abierto.value = false;
};

const limpiar = () => {
    valor.value = null;
    texto.value = '';
};

// Al salir sin elegir, se restaura la opción elegida (o queda vacío si se borró el texto).
const cerrar = () => {
    abierto.value = false;

    if (texto.value.trim() === '') {
        valor.value = null;
    }

    texto.value = nombreDe(valor.value);
};

const teclado = (e: KeyboardEvent) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();

        if (!abierto.value) {
            abrir();

            return;
        }

        const total = filtradas.value.length;

        if (total > 0) {
            activo.value =
                (activo.value + (e.key === 'ArrowDown' ? 1 : -1) + total) %
                total;
        }
    } else if (e.key === 'Enter') {
        if (abierto.value && filtradas.value[activo.value]) {
            e.preventDefault();
            elegir(filtradas.value[activo.value]);
        }
    } else if (e.key === 'Escape' && abierto.value) {
        e.preventDefault();
        cerrar();
    }
};

watch(texto, () => {
    if (abierto.value) {
        activo.value = 0;
    }
});
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            v-model="texto"
            type="text"
            role="combobox"
            autocomplete="off"
            :aria-expanded="abierto"
            :aria-controls="listaId"
            :aria-activedescendant="
                abierto && filtradas[activo]
                    ? `${id}-op-${filtradas[activo].id}`
                    : undefined
            "
            :placeholder="opciones.length ? placeholder : sinOpciones"
            :disabled="disabled || opciones.length === 0"
            class="h-10 w-full min-w-0 rounded-md border border-input bg-background py-1 pr-16 pl-3 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:bg-muted md:text-sm"
            @focus="abrir"
            @click="abrir"
            @input="abierto = true"
            @blur="cerrar"
            @keydown="teclado"
        />
        <div
            class="pointer-events-none absolute inset-y-0 right-2 flex items-center gap-1"
        >
            <button
                v-if="valor !== null && !disabled"
                type="button"
                class="pointer-events-auto rounded p-1 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                :aria-label="`Quitar la selección de ${nombreDe(valor)}`"
                @mousedown.prevent="limpiar"
            >
                <X class="size-3.5" />
            </button>
            <ChevronDown
                class="size-4 text-muted-foreground"
                aria-hidden="true"
            />
        </div>
        <ul
            v-show="abierto"
            :id="listaId"
            role="listbox"
            class="absolute z-30 mt-1 max-h-60 w-full overflow-auto rounded-md border border-border bg-popover py-1 text-sm shadow-lg"
        >
            <li
                v-for="(opcion, i) in filtradas"
                :id="`${id}-op-${opcion.id}`"
                :key="opcion.id"
                role="option"
                :aria-selected="String(opcion.id) === valor"
                class="cursor-pointer px-3 py-2"
                :class="[
                    i === activo ? 'bg-accent text-accent-foreground' : '',
                    String(opcion.id) === valor ? 'font-semibold' : '',
                ]"
                @mousedown.prevent="elegir(opcion)"
                @mousemove="activo = i"
            >
                {{ opcion.nombre }}
            </li>
            <li
                v-if="filtradas.length === 0"
                class="px-3 py-2 text-muted-foreground"
            >
                Sin coincidencias.
            </li>
        </ul>
    </div>
</template>
