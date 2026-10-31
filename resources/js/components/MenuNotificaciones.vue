<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
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
import { formatearFecha } from '@/lib/reservas';
import { index as solicitudes } from '@/routes/solicitudes';

const page = usePage();
const notificaciones = computed(() => page.props.demo?.notificaciones ?? []);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <BotonEncabezado
                :aria-label="`Notificaciones: ${notificaciones.length}`"
            >
                <Bell />
                <span
                    v-if="notificaciones.length > 0"
                    class="absolute -top-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full bg-white text-[10px] font-semibold text-rojo tabular-nums"
                >
                    {{ notificaciones.length }}
                </span>
            </BotonEncabezado>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80 max-w-[calc(100vw-2rem)]">
            <DropdownMenuLabel>Notificaciones</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <p
                v-if="notificaciones.length === 0"
                class="px-2 py-3 text-sm text-muted-foreground"
            >
                No tienes notificaciones.
            </p>
            <DropdownMenuItem
                v-for="notificacion in notificaciones"
                :key="notificacion.id"
                as-child
            >
                <Link
                    :href="solicitudes()"
                    class="flex w-full cursor-pointer flex-col items-start gap-0.5"
                >
                    <span class="w-full truncate font-medium">
                        {{ notificacion.actividad }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ notificacion.estado }} ·
                        {{ formatearFecha(notificacion.fecha) }}
                    </span>
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
