<script setup lang="ts">
import { onClickOutside } from '@vueuse/core';
import { ChevronDown, Plus } from '@lucide/vue';
import { computed, ref, useId, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { CuatrimestreGrupos, Opcion } from '@/types';

/**
 * Periodo cuatrimestral: el diseño y el funcionamiento de dev (0ff5e46) para marcar
 * cuatrimestres y sus grupos (A, B, C, …): se ofrecen de la A a la C (o hasta el último elegido)
 * y «+ Grupo» agrega la siguiente letra. Se usa para la licenciatura principal y para cada
 * licenciatura participante o invitada.
 *
 * Los cuatrimestres se eligen en un desplegable compacto de selección múltiple que ofrece del
 * primero al `limite` de la licenciatura (licenciaturas.cantidad_cuatrimestres). Debajo quedan
 * visibles solo los cuatrimestres marcados, con sus grupos, aunque el desplegable esté cerrado.
 */
const props = defineProps<{
    cuatrimestres: (Opcion & { numero: number })[];
    error?: string;
    // Texto de la leyenda (la licenciatura principal usa el original).
    leyenda?: string;
    // Cantidad de cuatrimestres de la licenciatura elegida; undefined = aún no hay licenciatura.
    limite?: number;
}>();

const seleccion = defineModel<CuatrimestreGrupos[]>({ required: true });

const LETRAS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gruposExtra = ref<Record<number, number>>({});
const buscarCuatrimestre = (id: number) =>
    seleccion.value.find((c) => c.id === id);
const marcado = (id: number) => buscarCuatrimestre(id) !== undefined;
const tieneGrupo = (id: number, letra: string) =>
    buscarCuatrimestre(id)?.grupos.includes(letra) ?? false;
const letrasDe = (id: number) => {
    const ultimo = Math.max(
        -1,
        ...(buscarCuatrimestre(id)?.grupos ?? []).map((g) => LETRAS.indexOf(g)),
    );

    return LETRAS.slice(
        0,
        Math.min(26, Math.max(3, ultimo + 1) + (gruposExtra.value[id] ?? 0)),
    ).split('');
};
const masGrupos = (id: number) => {
    gruposExtra.value[id] = (gruposExtra.value[id] ?? 0) + 1;
};
const alternarCuatrimestre = (id: number) => {
    if (marcado(id)) {
        seleccion.value = seleccion.value.filter((c) => c.id !== id);
    } else {
        seleccion.value = [...seleccion.value, { id, grupos: [] }].sort(
            (a, b) => a.id - b.id,
        );
    }
};
const alternarGrupo = (id: number, letra: string) => {
    const cuatrimestre = buscarCuatrimestre(id);

    if (!cuatrimestre) {
        return;
    }

    cuatrimestre.grupos = tieneGrupo(id, letra)
        ? cuatrimestre.grupos.filter((g) => g !== letra)
        : [...cuatrimestre.grupos, letra].sort();
};

// --- Desplegable --------------------------------------------------------------------------
const idLista = useId();
const abierto = ref(false);
const contenedor = useTemplateRef<HTMLElement>('contenedor');
onClickOutside(contenedor, () => (abierto.value = false));

const sinLicenciatura = computed(() => props.limite === undefined);
// Del primero al último cuatrimestre configurado para la licenciatura.
const disponibles = computed(() =>
    props.cuatrimestres.filter((c) => c.numero <= (props.limite ?? 0)),
);
// Los marcados, en orden; si alguno ya no existe en la licenciatura elegida se sigue mostrando
// (para poder desmarcarlo) con un aviso, y Laravel lo rechaza al guardar.
const marcados = computed(() =>
    props.cuatrimestres
        .filter((c) => marcado(c.id))
        .map((c) => ({ ...c, fuera: c.numero > (props.limite ?? Infinity) })),
);
const resumen = computed(() => {
    if (sinLicenciatura.value) {
        return 'Elige primero la licenciatura';
    }

    const n = marcados.value.length;

    if (n === 0) {
        return `Selecciona uno o varios cuatrimestres (${disponibles.value.length} disponibles)`;
    }

    return `${n} de ${disponibles.value.length} ${n === 1 ? 'cuatrimestre seleccionado' : 'cuatrimestres seleccionados'}`;
});
</script>

<template>
    <fieldset class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-2">
        <legend class="mb-2 text-sm font-medium">
            {{
                leyenda ??
                'Periodo cuatrimestral (opcional, puedes marcar varios)'
            }}
        </legend>
        <p class="-mt-1 text-xs text-muted-foreground">
            Elige los cuatrimestres en la lista y, a su lado, marca los grupos
            que participan.
        </p>

        <div ref="contenedor" class="relative" @keydown.esc="abierto = false">
            <button
                type="button"
                class="flex h-10 w-full min-w-0 items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-1 text-left text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-60 md:text-sm"
                :disabled="sinLicenciatura"
                aria-haspopup="true"
                :aria-expanded="abierto"
                :aria-controls="idLista"
                @click="abierto = !abierto"
            >
                <span
                    class="min-w-0 truncate"
                    :class="{ 'text-muted-foreground': marcados.length === 0 }"
                    >{{ resumen }}</span
                >
                <ChevronDown
                    class="size-4 shrink-0 text-muted-foreground transition-transform"
                    :class="{ 'rotate-180': abierto }"
                />
            </button>
            <div
                v-show="abierto"
                :id="idLista"
                class="absolute inset-x-0 z-30 mt-1 max-h-72 overflow-y-auto rounded-md border border-input bg-background py-1 shadow-md"
                role="group"
                :aria-label="leyenda ?? 'Cuatrimestres'"
            >
                <label
                    v-for="c in disponibles"
                    :key="c.id"
                    class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-accent"
                    :class="{ 'bg-secondary/60': marcado(c.id) }"
                >
                    <input
                        type="checkbox"
                        :checked="marcado(c.id)"
                        class="size-4 accent-rojo"
                        @change="alternarCuatrimestre(c.id)"
                    />
                    {{ c.nombre }}
                </label>
            </div>
        </div>

        <!-- Solo los cuatrimestres marcados, con sus grupos (diseño original de dev). -->
        <ul
            v-if="marcados.length > 0"
            class="divide-y divide-border rounded-md border border-input"
        >
            <li
                v-for="c in marcados"
                :key="c.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 bg-secondary/60 px-3 py-2"
            >
                <label
                    class="flex w-48 cursor-pointer items-center gap-2 text-sm"
                >
                    <input
                        type="checkbox"
                        :checked="marcado(c.id)"
                        class="size-4 accent-rojo"
                        @change="alternarCuatrimestre(c.id)"
                    />
                    {{ c.nombre }}
                </label>
                <div
                    class="flex flex-wrap items-center gap-1.5"
                    role="group"
                    :aria-label="`Grupos de ${c.nombre}`"
                >
                    <span class="mr-1 text-xs text-muted-foreground"
                        >Grupos:</span
                    >
                    <button
                        v-for="letra in letrasDe(c.id)"
                        :key="letra"
                        type="button"
                        class="h-7 min-w-9 rounded-md border px-2 text-xs font-semibold tabular-nums transition-colors"
                        :class="
                            tieneGrupo(c.id, letra)
                                ? 'border-rojo bg-rojo text-rojo-foreground'
                                : 'border-input bg-background hover:bg-accent'
                        "
                        :aria-pressed="tieneGrupo(c.id, letra)"
                        @click="alternarGrupo(c.id, letra)"
                    >
                        {{ c.numero }}{{ letra }}
                    </button>
                    <Button
                        v-if="letrasDe(c.id).length < 26"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-7 px-2 text-xs"
                        :aria-label="`Agregar otro grupo a ${c.nombre}`"
                        @click="masGrupos(c.id)"
                    >
                        <Plus class="size-3.5" />
                        Grupo
                    </Button>
                </div>
                <p v-if="c.fuera" class="w-full text-xs text-amber-700">
                    Esta licenciatura tiene {{ limite }} cuatrimestres: desmarca
                    el {{ c.nombre.toLowerCase() }}.
                </p>
            </li>
        </ul>
        <InputError :message="error" />
    </fieldset>
</template>
