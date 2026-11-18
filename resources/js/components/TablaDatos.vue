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
</script>

<template>
    <div ref="contenedor">
        <ul v-if="angosta" class="divide-y divide-border">
            <li
                v-for="fila in filas"
                :key="fila.id"
                class="grid gap-3 px-4 py-3"
            >
                <div v-if="titulo" class="min-w-0 font-medium">
                    <slot :name="titulo.clave" :fila="fila">
                        {{ fila[titulo.clave as keyof T] }}
                    </slot>
                </div>
                <dl
                    v-if="datos.length"
                    class="grid grid-cols-[minmax(0,7rem)_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm"
                >
                    <template v-for="columna in datos" :key="columna.clave">
                        <dt
                            class="pt-0.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ columna.titulo }}
                        </dt>
                        <dd class="min-w-0 break-words">
                            <slot :name="columna.clave" :fila="fila">
                                {{ fila[columna.clave as keyof T] }}
                            </slot>
                        </dd>
                    </template>
                </dl>
                <div v-for="columna in fijas" :key="columna.clave">
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
