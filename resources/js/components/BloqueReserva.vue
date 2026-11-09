<script setup lang="ts">
import {
    CircleCheck,
    CircleX,
    Copy,
    Info,
    Trash2,
    TriangleAlert,
    Wrench,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useDisponibilidadBloque } from '@/composables/useDisponibilidadBloque';
import type {
    DetalleEditable,
    DocenteOpcion,
    EspacioOpcion,
    Opcion,
} from '@/types';

/**
 * Un bloque de la solicitud: espacio, fecha, horario, docente responsable, alumnos
 * y requerimientos. El indicador de disponibilidad es solo informativo: el servidor
 * vuelve a comprobar todo al enviar y al autorizar.
 */
const props = defineProps<{
    indice: number;
    espacios: EspacioOpcion[];
    docentes: DocenteOpcion[];
    licenciaturaId: string | null;
    reservaId: number | null;
    // Números (1, 2, …) de otros bloques de esta solicitud con los que se traslapa.
    traslapaCon: number[];
    error: (campo: string) => string | undefined;
}>();

const detalle = defineModel<DetalleEditable>('detalle', { required: true });

defineEmits<{ duplicar: []; quitar: [] }>();

const textarea =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const opcionesEspacios = computed<Opcion[]>(() =>
    props.espacios.map((e) => ({
        id: e.id,
        nombre:
            e.nombre +
            (e.capacidad ? ` · ${e.capacidad} lugares` : '') +
            (e.estatus !== 'disponible' ? ' (en mantenimiento)' : ''),
    })),
);

const espacio = computed(
    () =>
        props.espacios.find(
            (e) => String(e.id) === String(detalle.value.espacio_id),
        ) ?? null,
);

// Solo docentes y coordinadores de la licenciatura elegida.
const opcionesDocentes = computed<Opcion[]>(() =>
    props.docentes
        .filter(
            (d) =>
                props.licenciaturaId !== null &&
                d.licenciatura_ids.includes(Number(props.licenciaturaId)),
        )
        .map((d) => ({ id: d.id, nombre: d.nombre })),
);

// Si cambia la licenciatura y el responsable ya no pertenece a ella, se quita.
watch(
    () => props.licenciaturaId,
    () => {
        const id = detalle.value.docente_responsable_id;

        if (
            id !== null &&
            !opcionesDocentes.value.some((d) => String(d.id) === id)
        ) {
            detalle.value.docente_responsable_id = null;
        }
    },
);

// Al cambiar de espacio se descartan los requerimientos que el nuevo espacio no tiene.
watch(
    () => detalle.value.espacio_id,
    () => {
        const ids = new Set(
            espacio.value?.requerimientos.map((r) => r.id) ?? [],
        );
        detalle.value.requerimientos = detalle.value.requerimientos.filter(
            (r) => ids.has(r.requerimiento_id),
        );
    },
);

const pedido = (id: number) =>
    detalle.value.requerimientos.find((r) => r.requerimiento_id === id);

const alternarRequerimiento = (
    id: number,
    marcado: boolean | 'indeterminate',
) => {
    if (marcado === true && !pedido(id)) {
        detalle.value.requerimientos.push({
            requerimiento_id: id,
            cantidad: 1,
        });
    } else if (marcado !== true) {
        detalle.value.requerimientos = detalle.value.requerimientos.filter(
            (r) => r.requerimiento_id !== id,
        );
    }
};

const cambiarCantidad = (id: number, valor: string | number) => {
    const actual = pedido(id);

    if (actual) {
        actual.cantidad = Number(valor) || 0;
    }
};

// Errores del servidor por requerimiento: detalles.N.requerimientos.J[.campo].
const errorRequerimiento = (id: number) => {
    const j = detalle.value.requerimientos.findIndex(
        (r) => r.requerimiento_id === id,
    );

    return j === -1
        ? undefined
        : (props.error(`requerimientos.${j}`) ??
              props.error(`requerimientos.${j}.cantidad`) ??
              props.error(`requerimientos.${j}.requerimiento_id`));
};

const ayudaAlumnos = computed(() => {
    const e = espacio.value;

    if (!e) {
        return null;
    }

    const partes = [
        e.minimo_alumnos ? `mínimo ${e.minimo_alumnos}` : null,
        e.maximo_alumnos ? `máximo ${e.maximo_alumnos}` : null,
        e.capacidad ? `capacidad ${e.capacidad}` : null,
    ].filter(Boolean);

    return partes.length ? `Espacio: ${partes.join(', ')}.` : null;
});

const excedeCapacidad = computed(
    () =>
        espacio.value?.capacidad != null &&
        detalle.value.cantidad_alumnos !== '' &&
        detalle.value.cantidad_alumnos > espacio.value.capacidad,
);

const { estado } = useDisponibilidadBloque(
    () => ({
        espacio_id: detalle.value.espacio_id,
        fecha: detalle.value.fecha,
        hora_inicio: detalle.value.hora_inicio,
        hora_fin: detalle.value.hora_fin,
    }),
    () => props.reservaId,
);

const avisoChoque = (c: {
    folio: string | null;
    hora_inicio: string;
    hora_fin: string;
}) => `${c.folio ?? 'Reservado'} · ${c.hora_inicio} – ${c.hora_fin}`;
</script>

<template>
    <div
        class="grid gap-4 border-t border-border p-4 first:border-t-0 md:grid-cols-4 md:p-6"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 md:col-span-4"
        >
            <h3 class="text-sm font-semibold">Espacio {{ indice + 1 }}</h3>
            <div class="flex gap-1">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    title="Copiar este bloque para otra fecha"
                    @click="$emit('duplicar')"
                >
                    <Copy class="size-4" />
                    Duplicar
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="$emit('quitar')"
                >
                    <Trash2 class="size-4" />
                    Quitar
                </Button>
            </div>
        </div>

        <CampoFormulario
            :id="`espacio-${indice}`"
            etiqueta="Espacio"
            :error="error('espacio_id')"
            class="md:col-span-2"
        >
            <CampoSelect
                :id="`espacio-${indice}`"
                v-model="detalle.espacio_id"
                :opciones="opcionesEspacios"
                vacio="Selecciona un espacio"
            />
        </CampoFormulario>
        <CampoFormulario
            :id="`docente-${indice}`"
            etiqueta="Docente responsable (opcional)"
            :error="error('docente_responsable_id')"
            class="md:col-span-2"
        >
            <CampoSelect
                :id="`docente-${indice}`"
                v-model="detalle.docente_responsable_id"
                :opciones="opcionesDocentes"
                :vacio="
                    licenciaturaId
                        ? 'Sin responsable asignado'
                        : 'Primero elige la licenciatura'
                "
            />
        </CampoFormulario>
        <CampoFormulario
            :id="`fecha-${indice}`"
            etiqueta="Fecha"
            :error="error('fecha')"
            class="md:col-span-2"
        >
            <Input
                :id="`fecha-${indice}`"
                v-model="detalle.fecha"
                type="date"
                required
            />
        </CampoFormulario>
        <CampoFormulario
            :id="`inicio-${indice}`"
            etiqueta="Hora de inicio"
            :error="error('hora_inicio')"
        >
            <Input
                :id="`inicio-${indice}`"
                v-model="detalle.hora_inicio"
                type="time"
                required
            />
        </CampoFormulario>
        <CampoFormulario
            :id="`fin-${indice}`"
            etiqueta="Hora de fin"
            :error="error('hora_fin')"
        >
            <Input
                :id="`fin-${indice}`"
                v-model="detalle.hora_fin"
                type="time"
                required
            />
        </CampoFormulario>

        <!-- Disponibilidad en vivo (informativa) -->
        <div class="md:col-span-4" aria-live="polite">
            <p
                v-if="traslapaCon.length"
                class="mb-2 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                Se traslapa con
                {{ traslapaCon.map((n) => `el espacio ${n}`).join(', ') }} de
                esta solicitud.
            </p>
            <p
                v-if="estado.tipo === 'incompleto'"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Info class="size-4 shrink-0" />
                Completa espacio, fecha y horario para consultar la
                disponibilidad.
            </p>
            <p
                v-else-if="estado.tipo === 'cargando'"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Spinner class="size-4" />
                Consultando disponibilidad…
            </p>
            <p
                v-else-if="estado.tipo === 'error'"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Info class="size-4 shrink-0" />
                {{ estado.mensaje }}
            </p>
            <template v-else>
                <p
                    v-if="estado.resultado.situacion === 'libre'"
                    class="flex items-center gap-2 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
                >
                    <CircleCheck class="size-4 shrink-0" />
                    Disponible en ese horario.
                </p>
                <p
                    v-else-if="
                        estado.resultado.situacion === 'fuera_de_servicio'
                    "
                    class="flex items-center gap-2 rounded-md border border-border bg-muted px-3 py-2 text-sm text-muted-foreground"
                >
                    <Wrench class="size-4 shrink-0" />
                    El espacio está fuera de servicio; no podrá enviarse a
                    administración.
                </p>
                <div
                    v-else
                    class="flex items-start gap-2 rounded-md border px-3 py-2 text-sm"
                    :class="
                        estado.resultado.situacion === 'ocupado'
                            ? 'border-red-200 bg-red-50 text-red-800'
                            : 'border-amber-200 bg-amber-50 text-amber-800'
                    "
                >
                    <component
                        :is="
                            estado.resultado.situacion === 'ocupado'
                                ? CircleX
                                : TriangleAlert
                        "
                        class="mt-0.5 size-4 shrink-0"
                    />
                    <div>
                        <p class="font-medium">
                            {{
                                estado.resultado.situacion === 'ocupado'
                                    ? 'Ocupado: ya hay una reserva autorizada en ese horario. No podrá autorizarse.'
                                    : 'Posible conflicto: hay solicitudes en revisión en ese horario.'
                            }}
                        </p>
                        <ul class="mt-1 list-disc pl-5">
                            <li
                                v-for="choque in estado.resultado.choques"
                                :key="`${choque.estado}-${choque.hora_inicio}-${choque.folio}`"
                            >
                                {{ avisoChoque(choque) }}
                                ({{
                                    choque.estado === 'autorizada'
                                        ? 'autorizada'
                                        : 'en revisión'
                                }})
                            </li>
                        </ul>
                        <p class="mt-1 text-xs opacity-80">
                            Puedes guardar el borrador; la disponibilidad se
                            confirma al autorizar.
                        </p>
                    </div>
                </div>
            </template>
        </div>

        <CampoFormulario
            :id="`alumnos-${indice}`"
            etiqueta="Cantidad de alumnos"
            :error="error('cantidad_alumnos')"
            class="md:col-span-2"
        >
            <Input
                :id="`alumnos-${indice}`"
                v-model.number="detalle.cantidad_alumnos"
                type="number"
                min="1"
            />
            <p v-if="ayudaAlumnos" class="text-xs text-muted-foreground">
                {{ ayudaAlumnos }}
            </p>
            <p v-if="excedeCapacidad" class="text-xs text-amber-700">
                Supera la capacidad del espacio; se rechazará al enviar a
                administración.
            </p>
        </CampoFormulario>
        <CampoFormulario
            :id="`lugar-${indice}`"
            etiqueta="Lugar específico (opcional)"
            :error="error('lugar_especifico')"
            class="md:col-span-2"
        >
            <Input
                :id="`lugar-${indice}`"
                v-model="detalle.lugar_especifico"
                maxlength="150"
            />
        </CampoFormulario>

        <!-- Requerimientos del espacio -->
        <div class="grid gap-2 md:col-span-4">
            <Label>Requerimientos (opcional)</Label>
            <p v-if="!espacio" class="text-sm text-muted-foreground">
                Elige un espacio para ver sus requerimientos.
            </p>
            <p
                v-else-if="espacio.requerimientos.length === 0"
                class="text-sm text-muted-foreground"
            >
                Este espacio no tiene requerimientos registrados.
            </p>
            <ul v-else class="grid gap-2 sm:grid-cols-2">
                <li
                    v-for="requerimiento in espacio.requerimientos"
                    :key="requerimiento.id"
                    class="grid gap-1 rounded-md border border-border px-3 py-2"
                >
                    <div class="flex items-center gap-3">
                        <Checkbox
                            :id="`req-${indice}-${requerimiento.id}`"
                            :model-value="!!pedido(requerimiento.id)"
                            @update:model-value="
                                (v) =>
                                    alternarRequerimiento(requerimiento.id, v)
                            "
                        />
                        <Label
                            :for="`req-${indice}-${requerimiento.id}`"
                            class="flex-1 font-normal"
                        >
                            {{ requerimiento.nombre }}
                            <span class="text-xs text-muted-foreground">
                                (hay {{ requerimiento.cantidad_disponible }})
                            </span>
                        </Label>
                        <Input
                            v-if="pedido(requerimiento.id)"
                            :aria-label="`Cantidad de ${requerimiento.nombre}`"
                            :model-value="pedido(requerimiento.id)?.cantidad"
                            type="number"
                            min="1"
                            :max="requerimiento.cantidad_disponible"
                            class="h-8 w-20"
                            @update:model-value="
                                (v) => cambiarCantidad(requerimiento.id, v)
                            "
                        />
                    </div>
                    <InputError
                        :message="errorRequerimiento(requerimiento.id)"
                    />
                </li>
            </ul>
            <InputError :message="error('requerimientos')" />
        </div>

        <CampoFormulario
            :id="`descripcion-${indice}`"
            etiqueta="Descripción de la actividad (opcional)"
            :error="error('descripcion_actividad')"
            class="md:col-span-2"
        >
            <textarea
                :id="`descripcion-${indice}`"
                v-model="detalle.descripcion_actividad"
                :class="textarea"
            />
        </CampoFormulario>
        <CampoFormulario
            :id="`material-${indice}`"
            etiqueta="Material requerido (opcional)"
            :error="error('material_requerido')"
            class="md:col-span-2"
        >
            <textarea
                :id="`material-${indice}`"
                v-model="detalle.material_requerido"
                :class="textarea"
            />
        </CampoFormulario>
    </div>
</template>
