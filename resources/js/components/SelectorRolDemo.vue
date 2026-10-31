<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { rol as cambiarRol } from '@/routes/demo';

// TEMPORAL: se elimina junto con DatosEjemplo cuando exista la autorización real.
const page = usePage();
const demo = computed(() => page.props.demo);

const seleccionar = (evento: Event) => {
    router.post(cambiarRol.url(), {
        rol: (evento.target as HTMLSelectElement).value,
    });
};
</script>

<template>
    <label
        v-if="demo"
        class="flex flex-col gap-1 p-2 text-xs text-muted-foreground"
    >
        Datos de ejemplo · Ver como
        <select
            :value="demo.rol"
            class="h-8 w-full rounded-md border border-input bg-transparent px-2 text-sm text-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            @change="seleccionar"
        >
            <option
                v-for="rol in demo.roles"
                :key="rol"
                :value="rol"
                class="bg-background"
            >
                {{ rol }}
            </option>
        </select>
    </label>
</template>
