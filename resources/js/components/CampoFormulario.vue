<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineProps<{
    id: string;
    etiqueta: string;
    error?: string;
}>();
</script>

<template>
    <!-- min-w-0 / minmax(0,1fr): el campo se encoge con la columna en pantallas angostas (un
         campo de fecha o un select con opciones largas no ensanchan el formulario). -->
    <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] content-start gap-1.5">
        <Label :for="id">{{ etiqueta }}</Label>
        <slot />
        <InputError :message="error" />
    </div>
</template>
