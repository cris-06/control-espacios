<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import { computed, reactive } from 'vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TablaReservas from '@/components/TablaReservas.vue';
import TarjetaIndicador from '@/components/TarjetaIndicador.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatearFecha, sinVacios } from '@/lib/reservas';
import { index } from '@/routes/reportes';
import type { Opcion, Reserva, Total } from '@/types';

type Filtros = {
    fecha_inicio: string | null;
    fecha_fin: string | null;
    espacio_id: string | null;
    licenciatura_id: string | null;
    estado_id: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Reportes',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    filtros: Filtros;
    errores: Partial<Record<keyof Filtros, string>>;
    opciones: {
        espacios: Opcion[];
        licenciaturas: Opcion[];
        estados: Opcion[];
    };
    resultados: Reserva[];
    totalesEstado: Total[];
    totalesEspacio: Total[];
}>();

const filtros = reactive<Filtros>({ ...props.filtros });

const hayErrores = computed(() => Object.keys(props.errores).length > 0);

const periodo = computed(() =>
    props.filtros.fecha_inicio && props.filtros.fecha_fin && !hayErrores.value
        ? `${formatearFecha(props.filtros.fecha_inicio)} al ${formatearFecha(props.filtros.fecha_fin)}`
        : '',
);

const maximoEspacio = computed(() =>
    Math.max(1, ...props.totalesEspacio.map(({ total }) => total)),
);

const consultar = () => {
    router.get(index.url(), sinVacios(filtros), {
        preserveState: true,
        preserveScroll: true,
    });
};

const imprimir = () => window.print();
</script>

<template>
    <Head title="Reportes" />

    <PaginaBase
        titulo="Reportes"
        :descripcion="
            periodo
                ? `Periodo: ${periodo}`
                : 'Filtra por espacio, periodo, licenciatura y estado.'
        "
    >
        <template #acciones>
            <Button
                variant="outline"
                class="print:hidden"
                :disabled="resultados.length === 0"
                @click="imprimir"
            >
                <Printer />
                Imprimir
            </Button>
        </template>

        <TarjetaSeccion titulo="Filtros" class="print:hidden">
            <form
                class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-5"
                @submit.prevent="consultar"
            >
                <CampoFormulario
                    id="fecha_inicio"
                    etiqueta="Desde"
                    :error="errores.fecha_inicio"
                >
                    <Input
                        id="fecha_inicio"
                        v-model="filtros.fecha_inicio as string"
                        type="date"
                        :aria-invalid="!!errores.fecha_inicio"
                    />
                </CampoFormulario>
                <CampoFormulario
                    id="fecha_fin"
                    etiqueta="Hasta"
                    :error="errores.fecha_fin"
                >
                    <Input
                        id="fecha_fin"
                        v-model="filtros.fecha_fin as string"
                        type="date"
                        :aria-invalid="!!errores.fecha_fin"
                    />
                </CampoFormulario>
                <CampoFormulario
                    id="espacio"
                    etiqueta="Espacio"
                    :error="errores.espacio_id"
                >
                    <CampoSelect
                        id="espacio"
                        v-model="filtros.espacio_id"
                        :opciones="opciones.espacios"
                        vacio="Todos los espacios"
                    />
                </CampoFormulario>
                <CampoFormulario
                    id="licenciatura"
                    etiqueta="Licenciatura"
                    :error="errores.licenciatura_id"
                >
                    <CampoSelect
                        id="licenciatura"
                        v-model="filtros.licenciatura_id"
                        :opciones="opciones.licenciaturas"
                        vacio="Todas las licenciaturas"
                    />
                </CampoFormulario>
                <CampoFormulario
                    id="estado"
                    etiqueta="Estado"
                    :error="errores.estado_id"
                >
                    <CampoSelect
                        id="estado"
                        v-model="filtros.estado_id"
                        :opciones="opciones.estados"
                        vacio="Todos los estados"
                    />
                </CampoFormulario>
                <div class="flex justify-end sm:col-span-2 lg:col-span-5">
                    <Button type="submit">Generar reporte</Button>
                </div>
            </form>
        </TarjetaSeccion>

        <template v-if="!hayErrores">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <TarjetaIndicador
                    titulo="Total de reservaciones"
                    :valor="resultados.length"
                />
                <TarjetaIndicador
                    v-for="total in totalesEstado"
                    :key="total.nombre"
                    :titulo="total.nombre"
                    :valor="total.total"
                />
            </div>

            <TarjetaSeccion
                titulo="Uso por espacio"
                descripcion="Reservaciones del periodo en cada espacio."
            >
                <ul v-if="totalesEspacio.length > 0">
                    <li
                        v-for="total in totalesEspacio"
                        :key="total.nombre"
                        class="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_2rem] items-center gap-3 border-t border-border px-4 py-2.5 text-sm first:border-t-0 hover:bg-muted sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_2rem]"
                        :title="`${total.nombre}: ${total.total}`"
                    >
                        <span class="truncate">{{ total.nombre }}</span>
                        <span class="block">
                            <span
                                class="block h-2.5 rounded-r-sm bg-primary"
                                :style="{
                                    width: `${(total.total / maximoEspacio) * 100}%`,
                                }"
                            ></span>
                        </span>
                        <span class="text-right font-medium tabular-nums">
                            {{ total.total }}
                        </span>
                    </li>
                </ul>
                <p v-else class="px-4 py-10 text-center text-muted-foreground">
                    Sin datos para este periodo.
                </p>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Detalle">
                <TablaReservas
                    :reservas="resultados"
                    mostrar-docente
                    vacio="No hay reservaciones con estos filtros."
                />
            </TarjetaSeccion>
        </template>
    </PaginaBase>
</template>
