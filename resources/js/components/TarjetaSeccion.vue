<script setup lang="ts">
defineProps<{
    titulo?: string;
    descripcion?: string;
}>();
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-border bg-card shadow-sm print:shadow-none"
    >
        <header
            v-if="titulo"
            class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3"
        >
            <div class="min-w-0">
                <h2 class="font-semibold text-rojo">{{ titulo }}</h2>
                <p v-if="descripcion" class="text-sm text-muted-foreground">
                    {{ descripcion }}
                </p>
            </div>
            <slot name="acciones" />
        </header>
        <slot />
        <footer
            v-if="$slots.pie"
            class="border-t border-border px-4 py-3 text-xs text-muted-foreground"
        >
            <slot name="pie" />
        </footer>
    </section>
</template>
