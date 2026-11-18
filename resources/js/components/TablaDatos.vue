<script setup lang="ts" generic="T extends { id: number | string }">
defineProps<{
    // `fija`: la columna queda pegada al borde derecho al desplazar la tabla (p. ej. ACCIONES).
    columnas: { clave: string; titulo: string; fija?: boolean }[];
    filas: T[];
    vacio: string;
}>();
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-secondary text-secondary-foreground">
                <tr>
                    <th
                        v-for="columna in columnas"
                        :key="columna.clave"
                        scope="col"
                        class="px-4 py-2.5 text-xs font-semibold tracking-wide whitespace-nowrap uppercase"
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
                        class="px-4 py-3"
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
</template>
