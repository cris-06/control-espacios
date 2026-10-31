<script setup lang="ts">
defineProps<{
    titulo: string;
    valor: number;
    descripcion?: string;
}>();
</script>

<template>
    <div
        class="rounded-xl border border-t-[3px] border-border border-t-primary bg-card p-4 shadow-sm print:shadow-none"
    >
        <p class="text-sm font-medium text-muted-foreground">{{ titulo }}</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums">{{ valor }}</p>
        <p v-if="descripcion" class="mt-1 text-xs text-muted-foreground">
            {{ descripcion }}
        </p>
    </div>
</template>
