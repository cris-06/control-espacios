<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { FilePen, Plus } from '@lucide/vue';
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
    filtros: { estado_id: number | null; licenciatura_id: number | null };
    opcionesEstados: Opcion[];
    opcionesLicenciaturas: Opcion[];
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

const licenciaturaId = ref(
    props.filtros.licenciatura_id ? String(props.filtros.licenciatura_id) : null,
);

// «Mis borradores» usa el filtro de estado existente (estados_reserva.id = 1, borrador).
// Los borradores solo los ve quien los creó (Reserva::visiblesPara), así que son los propios.
const ESTADO_BORRADOR = '1';
const viendoBorradores = computed(() => estadoId.value === ESTADO_BORRADOR);
const alternarBorradores = () => {
    estadoId.value = viendoBorradores.value ? null : ESTADO_BORRADOR;
};

// Solo se ofrecen las licenciaturas propias; el backend responde 403 a cualquier otra.
watch([estadoId, licenciaturaId], ([estado, licenciatura]) => {
    router.get(
        index.url(),
        sinVacios({ estado_id: estado, licenciatura_id: licenciatura }),
        { preserveState: true, preserveScroll: true },
    );
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
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    :aria-pressed="viendoBorradores"
                    :class="viendoBorradores ? 'bg-accent' : ''"
                    @click="alternarBorradores"
                >
                    <FilePen class="size-4" />
                    {{ viendoBorradores ? 'Ver todas' : 'Mis borradores' }}
                </Button>
                <Button as-child>
                    <Link :href="create()">
                        <Plus class="size-4" />
                        Nueva reserva
                    </Link>
                </Button>
            </div>
        </template>

        <TarjetaSeccion titulo="Listado" :descripcion="conteo">
            <template #acciones>
                <div
                    v-if="opcionesLicenciaturas.length > 1"
                    class="w-full sm:w-64"
                >
                    <CampoFormulario id="licenciatura" etiqueta="Licenciatura">
                        <CampoSelect
                            id="licenciatura"
                            v-model="licenciaturaId"
                            :opciones="opcionesLicenciaturas"
                            vacio="Todas mis licenciaturas"
                        />
                    </CampoFormulario>
                </div>
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
                vacio="No hay solicitudes con estos filtros."
            />
        </TarjetaSeccion>
    </PaginaBase>
</template>
