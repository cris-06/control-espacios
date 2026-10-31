<script setup lang="ts">
import type { Opcion } from '@/types';

defineProps<{
    opciones: Opcion[];
    vacio: string;
}>();

const modelo = defineModel<string | number | null>();
</script>

<template>
    <select
        v-model="modelo"
        class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm"
    >
        <option :value="null" class="bg-background">{{ vacio }}</option>
        <option
            v-for="opcion in opciones"
            :key="opcion.id"
            :value="String(opcion.id)"
            class="bg-background"
        >
            {{ opcion.nombre }}
        </option>
    </select>
</template>
