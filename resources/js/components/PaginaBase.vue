<script setup lang="ts">
defineProps<{
    titulo: string;
    descripcion?: string;
}>();
</script>

<template>
    <div class="flex flex-1 flex-col">
        <div class="border-b border-border bg-card">
            <div
                class="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-5 md:px-6"
            >
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight text-rojo">
                        {{ titulo }}
                    </h1>
                    <p
                        v-if="descripcion"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ descripcion }}
                    </p>
                </div>
                <slot name="acciones" />
            </div>
        </div>
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
            <slot />
        </div>
    </div>
</template>
