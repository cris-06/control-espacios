<script setup lang="ts">
import {
    Ban,
    CircleCheck,
    CircleX,
    Clock,
    FilePen,
    TriangleAlert,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{
    estadoId: number;
    estado: string;
}>();

const estilos: Record<number, { icono: Component; color: string }> = {
    1: {
        icono: FilePen,
        color: 'border-border bg-muted text-muted-foreground',
    },
    2: { icono: Clock, color: 'border-amber-200 bg-amber-50 text-amber-800' },
    3: {
        icono: TriangleAlert,
        color: 'border-orange-200 bg-orange-50 text-orange-800',
    },
    4: { icono: Clock, color: 'border-amber-200 bg-amber-50 text-amber-800' },
    5: {
        icono: CircleCheck,
        color: 'border-green-200 bg-green-50 text-green-800',
    },
    6: { icono: CircleX, color: 'border-red-200 bg-red-50 text-red-800' },
    7: { icono: Ban, color: 'border-border bg-muted text-muted-foreground' },
};

const estilo = computed(() => estilos[props.estadoId] ?? estilos[1]);
</script>

<template>
    <Badge
        variant="outline"
        class="gap-1 font-medium whitespace-nowrap"
        :class="estilo.color"
    >
        <component :is="estilo.icono" class="size-3" />
        {{ estado }}
    </Badge>
</template>
