<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TablaReservas from '@/components/TablaReservas.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Button } from '@/components/ui/button';
import { sinVacios } from '@/lib/reservas';
import { create } from '@/routes/reservas';
import { index } from '@/routes/solicitudes';
import type { Opcion, ReservaFila } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Solicitudes',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    filtros: { estado_id: number | null };
    opcionesEstados: Opcion[];
    solicitudes: ReservaFila[];
    puedeCrear: boolean;
}>();

const page = usePage();
const esDocente = computed(() => page.props.auth.rol?.nombre === 'docente');

const conteo = computed(() =>
    props.solicitudes.length === 1
        ? '1 solicitud'
        : `${props.solicitudes.length} solicitudes`,
);

const estadoId = ref(
    props.filtros.estado_id ? String(props.filtros.estado_id) : null,
);

watch(estadoId, (valor) => {
    router.get(index.url(), sinVacios({ estado_id: valor }), {
        preserveState: true,
        preserveScroll: true,
    });
});
</script>

<template>
    <Head :title="esDocente ? 'Mis solicitudes' : 'Solicitudes'" />

    <PaginaBase
        :titulo="esDocente ? 'Mis solicitudes' : 'Solicitudes'"
        :descripcion="
            esDocente
                ? 'Consulta el avance de las solicitudes que creaste.'
                : 'Solicitudes visibles para tu rol.'
        "
    >
        <template v-if="puedeCrear" #acciones>
            <Button as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    Nueva reserva
                </Link>
            </Button>
        </template>

        <TarjetaSeccion titulo="Listado" :descripcion="conteo">
            <template #acciones>
                <div class="w-full sm:w-64">
                    <CampoFormulario id="estado" etiqueta="Estado">
                        <CampoSelect
                            id="estado"
                            v-model="estadoId"
                            :opciones="opcionesEstados"
                            vacio="Todos los estados"
                        />
                    </CampoFormulario>
                </div>
            </template>
            <TablaReservas
                :reservas="solicitudes"
                :mostrar-docente="!esDocente"
                enlazar
                vacio="No hay solicitudes con este estado."
            />
        </TarjetaSeccion>
    </PaginaBase>
</template>
