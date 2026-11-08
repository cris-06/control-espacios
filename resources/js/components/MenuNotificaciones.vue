<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';
import BotonEncabezado from '@/components/BotonEncabezado.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { haceCuanto } from '@/lib/reservas';
import { index, leer, leerTodas } from '@/routes/notificaciones';
import type { Notificacion } from '@/types';

const page = usePage();
const noLeidas = computed(() => page.props.notificaciones?.noLeidas ?? 0);
const recientes = computed(() => page.props.notificaciones?.recientes ?? []);

const colorTipo: Record<string, string> = {
    success: 'bg-green-600',
    warning: 'bg-amber-500',
    error: 'bg-red-600',
    info: 'bg-sky-600',
};

// Marcar como leída es un POST: abre la reserva si la notificación tiene una.
const abrir = (notificacion: Notificacion) => {
    router.post(leer.url(notificacion.id), {}, { preserveScroll: true });
};

const marcarTodas = () => {
    router.post(leerTodas.url(), {}, { preserveScroll: true });
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <BotonEncabezado
                :aria-label="`Notificaciones sin leer: ${noLeidas}`"
            >
                <Bell />
                <span
                    v-if="noLeidas > 0"
                    class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-white px-1 text-[10px] font-semibold text-rojo tabular-nums"
                >
                    {{ noLeidas > 99 ? '99+' : noLeidas }}
                </span>
            </BotonEncabezado>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-96 max-w-[calc(100vw-2rem)]">
            <div class="flex items-center justify-between gap-2 pr-2">
                <DropdownMenuLabel>Notificaciones</DropdownMenuLabel>
                <button
                    v-if="noLeidas > 0"
                    type="button"
                    class="text-xs text-rojo underline-offset-4 hover:underline"
                    @click="marcarTodas"
                >
                    Marcar todas como leídas
                </button>
            </div>
            <DropdownMenuSeparator />
            <p
                v-if="recientes.length === 0"
                class="px-2 py-3 text-sm text-muted-foreground"
            >
                No tienes notificaciones.
            </p>
            <DropdownMenuItem
                v-for="notificacion in recientes"
                :key="notificacion.id"
                class="flex cursor-pointer items-start gap-2"
                @select="abrir(notificacion)"
            >
                <span
                    class="mt-1.5 size-2 shrink-0 rounded-full"
                    :class="
                        notificacion.leida
                            ? 'bg-transparent'
                            : (colorTipo[notificacion.tipo] ?? colorTipo.info)
                    "
                    :aria-label="notificacion.leida ? 'Leída' : 'Sin leer'"
                />
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span
                        class="truncate"
                        :class="notificacion.leida ? '' : 'font-semibold'"
                    >
                        {{ notificacion.titulo }}
                    </span>
                    <span class="line-clamp-2 text-xs text-muted-foreground">
                        {{ notificacion.mensaje }}
                    </span>
                    <span
                        v-if="notificacion.fecha"
                        class="text-[11px] text-muted-foreground"
                    >
                        {{ haceCuanto(notificacion.fecha) }}
                    </span>
                </span>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link :href="index()" class="w-full justify-center text-sm">
                    Ver todas
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
