<script setup lang="ts" generic="T extends { id: number | string }">
defineProps<{
    columnas: { clave: string; titulo: string }[];
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
                    >
                        {{ columna.titulo }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="fila in filas"
                    :key="fila.id"
                    class="border-t border-border transition-colors hover:bg-muted"
                >
                    <td
                        v-for="columna in columnas"
                        :key="columna.clave"
                        class="px-4 py-3"
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
