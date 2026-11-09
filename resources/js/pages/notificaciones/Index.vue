<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCheck } from '@lucide/vue';
import { computed } from 'vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Button } from '@/components/ui/button';
import { formatearFechaHora } from '@/lib/reservas';
import { index, leer, leerTodas } from '@/routes/notificaciones';
import type { Notificacion, Paginado } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notificaciones', href: index() }],
    },
});

const props = defineProps<{
    notificaciones: Paginado<Notificacion>;
}>();

const hayNoLeidas = computed(() =>
    props.notificaciones.data.some((n) => !n.leida),
);

const borde: Record<string, string> = {
    success: 'border-l-green-600',
    warning: 'border-l-amber-500',
    error: 'border-l-red-600',
    info: 'border-l-sky-600',
};

const abrir = (notificacion: Notificacion) =>
    router.post(leer.url(notificacion.id), {}, { preserveScroll: true });
</script>

<template>
    <Head title="Notificaciones" />

    <PaginaBase
        titulo="Notificaciones"
        descripcion="Avisos sobre tus reservas y las que esperan tu revisión."
    >
        <template v-if="hayNoLeidas" #acciones>
            <Button
                variant="outline"
                @click="
                    router.post(leerTodas.url(), {}, { preserveScroll: true })
                "
            >
                <CheckCheck class="size-4" />
                Marcar todas como leídas
            </Button>
        </template>

        <TarjetaSeccion
            titulo="Todas"
            :descripcion="`${notificaciones.total} notificación(es)`"
        >
            <p
                v-if="notificaciones.data.length === 0"
                class="px-4 py-10 text-center text-sm text-muted-foreground"
            >
                No tienes notificaciones.
            </p>
            <ul v-else class="divide-y divide-border">
                <li
                    v-for="notificacion in notificaciones.data"
                    :key="notificacion.id"
                >
                    <button
                        type="button"
                        class="flex w-full flex-col gap-1 border-l-4 px-4 py-3 text-left transition-colors hover:bg-muted"
                        :class="[
                            borde[notificacion.tipo] ?? borde.info,
                            notificacion.leida ? 'opacity-70' : 'bg-card',
                        ]"
                        @click="abrir(notificacion)"
                    >
                        <span
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <span
                                :class="
                                    notificacion.leida ? '' : 'font-semibold'
                                "
                            >
                                {{ notificacion.titulo }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                <template v-if="!notificacion.leida"
                                    >Sin leer ·
                                </template>
                                {{
                                    notificacion.fecha
                                        ? formatearFechaHora(notificacion.fecha)
                                        : ''
                                }}
                            </span>
                        </span>
                        <span
                            class="text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ notificacion.mensaje }}
                        </span>
                    </button>
                </li>
            </ul>

            <template v-if="notificaciones.last_page > 1" #pie>
                <div class="flex items-center justify-between gap-3">
                    <span>
                        {{ notificaciones.from }}–{{ notificaciones.to }} de
                        {{ notificaciones.total }}
                    </span>
                    <div class="flex gap-2">
                        <Button
                            v-if="notificaciones.prev_page_url"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="notificaciones.prev_page_url"
                                >Anterior</Link
                            >
                        </Button>
                        <Button
                            v-if="notificaciones.next_page_url"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="notificaciones.next_page_url"
                                >Siguiente</Link
                            >
                        </Button>
                    </div>
                </div>
            </template>
        </TarjetaSeccion>
    </PaginaBase>
</template>
