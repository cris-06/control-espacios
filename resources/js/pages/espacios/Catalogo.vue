
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, MapPin } from '@lucide/vue';
import PaginaBase from '@/components/PaginaBase.vue';

type EspacioCatalogo = {
    id: number;
    nombre: string;
    ubicacion: string | null;
    foto: string | null;
    capacidad: number | null;
    minimo_alumnos: number | null;
    maximo_alumnos: number | null;
    requerimientos: {
        nombre: string;
        cantidad_disponible: number;
    }[];
};

defineProps<{
    espacios: EspacioCatalogo[];
}>();
</script>

<template>
    <Head title="Espacios para Eventos" />

    <PaginaBase
        titulo="Espacios para Eventos"
        descripcion="Consulta las instalaciones disponibles y selecciona el espacio que deseas reservar."
    >
        <p
            v-if="espacios.length === 0"
            class="rounded-lg border p-6 text-sm text-muted-foreground"
        >
            No hay espacios disponibles por el momento.
        </p>

        <div
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <article
                v-for="espacio in espacios"
                :key="espacio.id"
                class="flex flex-col overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <img
                    v-if="espacio.foto"
                    :src="espacio.foto"
                    :alt="`Fotografía de ${espacio.nombre}`"
                    class="h-44 w-full object-cover"
                />

                <div
                    v-else
                    class="flex h-44 items-center justify-center bg-blue-100"
                >
                    <Building2 class="size-12 text-blue-600" />
                </div>

                <div class="flex flex-1 flex-col gap-2 p-4">
                    <h2 class="font-semibold">
                        {{ espacio.nombre }}
                    </h2>

                    <p class="flex items-center gap-1 text-sm text-muted-foreground">
                        <MapPin class="size-4 shrink-0" />
                        {{ espacio.ubicacion || 'Sin ubicación registrada' }}
                    </p>

                    <p class="text-sm">
                        Capacidad:
                        <strong>{{ espacio.capacidad ?? 'No especificada' }}</strong>
                    </p>

                    <p class="text-sm text-muted-foreground">
                        Alumnos permitidos:
                        {{ espacio.minimo_alumnos ?? '—' }}
                        a
                        {{ espacio.maximo_alumnos ?? '—' }}
                    </p>

                    <div class="rounded-lg bg-muted/50 p-3">
                        <h3 class="mb-2 text-xs font-semibold">
                            REQUERIMIENTOS DISPONIBLES
                        </h3>

                        <p
                            v-if="espacio.requerimientos.length === 0"
                            class="text-xs text-muted-foreground"
                        >
                            No tiene requerimientos registrados.
                        </p>

                        <ul v-else class="space-y-1 text-sm">
                            <li
                                v-for="requerimiento in espacio.requerimientos"
                                :key="requerimiento.nombre"
                            >
                                {{ requerimiento.nombre }}:
                                {{ requerimiento.cantidad_disponible }}
                            </li>
                        </ul>
                    </div>

                    <div class="mt-auto pt-2">
                        <Link
                            :href="`/reservas/create?espacio_id=${espacio.id}`"
                            class="block rounded-md bg-blue-700 px-4 py-2 text-center text-sm font-medium text-white hover:bg-blue-800"
                        >
                            Reservar este espacio
                        </Link>
                    </div>
                </div>
            </article>
        </div>
    </PaginaBase>
</template>
