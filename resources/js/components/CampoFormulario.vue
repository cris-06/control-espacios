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
    <div class="grid content-start gap-1.5">
        <Label :for="id">{{ etiqueta }}</Label>
        <slot />
        <InputError :message="error" />
    </div>
</template>
