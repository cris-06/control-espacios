<script setup lang="ts" generic="T extends { id: number | string }">
import { useElementSize } from '@vueuse/core';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';

const props = defineProps<{
    // `fija`: la columna queda pegada al borde derecho al desplazar la tabla (p. ej. ACCIONES).
    columnas: { clave: string; titulo: string; fija?: boolean }[];
    filas: T[];
    vacio: string;
}>();

// Si la tabla no cabe en el espacio disponible (celular, o tableta con el menú abierto),
// cada fila se muestra como tarjeta: la primera columna es el título, las demás quedan como
// «etiqueta: valor» y la columna fija (acciones) va al pie. El ancho que necesita la tabla
// se mide mientras se muestra; sin medición aún, se estima por columnas.
const ANCHO_MINIMO_COLUMNA = 100;
// Desde este ancho (escritorio) siempre se usa la tabla, aunque deba desplazarse un poco.
const ANCHO_TABLA_SIEMPRE = 900;
const contenedor = useTemplateRef<HTMLElement>('contenedor');
const tabla = useTemplateRef<HTMLTableElement>('tabla');
const { width } = useElementSize(contenedor);
const requerido = ref(0);
const angosta = computed(
    () =>
        width.value > 0 &&
        width.value < ANCHO_TABLA_SIEMPRE &&
        width.value <
            Math.max(
                requerido.value,
                props.columnas.length * ANCHO_MINIMO_COLUMNA,
            ),
);

watch(
    [width, () => props.filas, () => props.columnas],
    async () => {
        if (angosta.value) {
            return;
        }

        await nextTick();

        if (tabla.value) {
            // Ancho mínimo sin desbordar (el de `w-full` sería el del contenedor).
            tabla.value.style.width = 'min-content';
            requerido.value = tabla.value.offsetWidth;
            tabla.value.style.width = '';
        }
    },
    { immediate: true, flush: 'post' },
);
const titulo = computed(() => props.columnas[0]);
const datos = computed(() =>
    props.columnas.slice(1).filter((columna) => !columna.fija),
);
const fijas = computed(() =>
    props.columnas.slice(1).filter((columna) => columna.fija),
);

// En las tarjetas, insignias y botones no se recortan: ocupan como máximo el ancho disponible y
// su texto puede partirse en dos líneas.
const ajustable =
    '[&_[data-slot=badge]]:h-auto [&_[data-slot=badge]]:max-w-full [&_[data-slot=badge]]:shrink [&_[data-slot=badge]]:text-left [&_[data-slot=badge]]:whitespace-normal [&_[data-slot=button]]:h-auto [&_[data-slot=button]]:min-h-8 [&_[data-slot=button]]:max-w-full [&_[data-slot=button]]:text-left [&_[data-slot=button]]:whitespace-normal';
</script>

<template>
    <div ref="contenedor">
        <ul v-if="angosta" class="divide-y divide-border">
            <!-- Tarjeta por fila. Muy angosta (< 18rem): la etiqueta va encima del valor. Las
                 insignias y botones del valor pueden partirse en dos líneas en vez de recortarse. -->
            <li
                v-for="fila in filas"
                :key="fila.id"
                class="@container grid min-w-0 gap-3 px-4 py-3"
            >
                <div v-if="titulo" class="min-w-0 font-medium break-words">
                    <slot :name="titulo.clave" :fila="fila">
                        {{ fila[titulo.clave as keyof T] }}
                    </slot>
                </div>
                <dl
                    v-if="datos.length"
                    class="grid grid-cols-1 gap-x-3 gap-y-0.5 text-sm @2xs:grid-cols-[minmax(0,7rem)_minmax(0,1fr)] @2xs:gap-y-1.5"
                >
                    <template v-for="columna in datos" :key="columna.clave">
                        <dt
                            class="pt-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase first:pt-0 @2xs:pt-0.5"
                        >
                            {{ columna.titulo }}
                        </dt>
                        <dd class="min-w-0 break-words" :class="ajustable">
                            <slot :name="columna.clave" :fila="fila">
                                {{ fila[columna.clave as keyof T] }}
                            </slot>
                        </dd>
                    </template>
                </dl>
                <div
                    v-for="columna in fijas"
                    :key="columna.clave"
                    class="min-w-0"
                    :class="ajustable"
                >
                    <slot :name="columna.clave" :fila="fila">
                        {{ fila[columna.clave as keyof T] }}
                    </slot>
                </div>
            </li>
            <li
                v-if="filas.length === 0"
                class="px-4 py-10 text-center text-sm text-muted-foreground"
            >
                {{ vacio }}
            </li>
        </ul>

        <div v-else class="overflow-x-auto">
            <table ref="tabla" class="w-full text-left text-sm">
                <thead class="bg-secondary text-secondary-foreground">
                    <tr>
                        <th
                            v-for="columna in columnas"
                            :key="columna.clave"
                            scope="col"
                            class="px-3 py-2.5 text-xs font-semibold tracking-wide whitespace-nowrap uppercase first:pl-4 last:pr-4"
                            :class="
                                columna.fija
                                    ? 'sticky right-0 z-10 bg-secondary shadow-[-6px_0_8px_-6px_rgb(0_0_0/0.15)]'
                                    : ''
                            "
                        >
                            {{ columna.titulo }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="fila in filas"
                        :key="fila.id"
                        class="group border-t border-border transition-colors hover:bg-muted"
                    >
                        <td
                            v-for="columna in columnas"
                            :key="columna.clave"
                            class="px-3 py-3 first:pl-4 last:pr-4"
                            :class="
                                columna.fija
                                    ? 'sticky right-0 bg-card shadow-[-6px_0_8px_-6px_rgb(0_0_0/0.15)] transition-colors group-hover:bg-muted'
                                    : ''
                            "
                        >
                            <slot :name="columna.clave" :fila="fila">
                                {{ fila[columna.clave as keyof T] }}
                            </slot>
                        </td>
                    </tr>
                    <tr v-if="filas.length === 0">
                        <td
                            :colspan="columnas.length"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ vacio }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
