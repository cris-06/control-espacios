<script setup lang="ts">
import {
    ChevronRight,
    CircleCheck,
    CircleX,
    Copy,
    Info,
    Search,
    Trash2,
    TriangleAlert,
    Wrench,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import InputError from '@/components/InputError.vue';
import SelectorBuscable from '@/components/SelectorBuscable.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useDisponibilidadBloque } from '@/composables/useDisponibilidadBloque';
import { formatearFecha } from '@/lib/reservas';
import type {
    DetalleEditable,
    DocenteOpcion,
    EspacioOpcion,
    Opcion,
} from '@/types';

/**
 * Un espacio de la solicitud (Formato 10): espacio, responsable, fecha, horario, alumnos y
 * requerimientos. El indicador de disponibilidad es solo informativo: el servidor vuelve a
 * comprobar todo al enviar y al autorizar.
 */
const props = defineProps<{
    indice: number;
    espacios: EspacioOpcion[];
    docentes: DocenteOpcion[];
    licenciaturaId: string | null;
    reservaId: number | null;
    // Números (1, 2, …) de otros espacios de esta solicitud con los que se traslapa.
    traslapaCon: number[];
    // Primer día que se puede elegir (AAAA-MM-DD), o null si este espacio no debe cumplir
    // la anticipación (fechas ya enviadas que no cambiaron).
    fechaMinima: string | null;
    // Aviso de anticipación ya redactado (null si la fecha cumple).
    avisoAnticipacion: string | null;
    // Con un solo espacio no se ofrece eliminarlo desde aquí si no se quiere; se decide arriba.
    error: (campo: string) => string | undefined;
}>();

const detalle = defineModel<DetalleEditable>('detalle', { required: true });

defineEmits<{ duplicar: []; quitar: [] }>();

const textarea =
    'min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

// Buscador: filtra la lista del selector (el espacio elegido siempre se conserva en la lista).
const filtroEspacio = ref('');
const normalizar = (s: string) =>
    s
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

const opcionesEspacios = computed<Opcion[]>(() => {
    const buscado = normalizar(filtroEspacio.value.trim());

    return props.espacios
        .filter(
            (e) =>
                buscado === '' ||
                String(e.id) === String(detalle.value.espacio_id) ||
                normalizar(`${e.nombre} ${e.ubicacion ?? ''}`).includes(
                    buscado,
                ),
        )
        .map((e) => ({
            id: e.id,
            nombre:
                e.nombre +
                (e.capacidad ? ` · ${e.capacidad} lugares` : '') +
                (e.estatus !== 'disponible' ? ' (en mantenimiento)' : ''),
        }));
});

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

// Mismos límites que valida Laravel al guardar (ValidacionDetalleReserva::errorAlumnos): el
// mínimo de alumnos del espacio y, como máximo, el menor de su capacidad y su máximo de
// alumnos. Aquí solo se avisa antes de guardar.
const errorAlumnos = computed(() => {
    const e = espacio.value;
    const cantidad = detalle.value.cantidad_alumnos;
    const limites = [e?.capacidad, e?.maximo_alumnos].filter(
        (l): l is number => typeof l === 'number' && l > 0,
    );

    if (!e || cantidad === '') {
        return null;
    }

    if (e.minimo_alumnos && cantidad < e.minimo_alumnos) {
        return `Son ${cantidad} alumnos y ${e.nombre} requiere como mínimo ${e.minimo_alumnos}.`;
    }

    if (limites.length === 0) {
        return null;
    }

    const limite = Math.min(...limites);

    return cantidad > limite
        ? `Son ${cantidad} alumnos y ${e.nombre} admite como máximo ${limite}.`
        : null;
});

// «Más datos de este espacio»: abierto si ya tiene alguno (para no esconder lo capturado).
const masDatosAbierto = ref(
    Boolean(
        detalle.value.lugar_especifico ||
        detalle.value.descripcion_actividad ||
        detalle.value.observaciones,
    ),
);
const errorMasDatos = computed(
    () =>
        props.error('lugar_especifico') ??
        props.error('descripcion_actividad') ??
        props.error('observaciones'),
);
watch(errorMasDatos, (e) => {
    if (e) {
        masDatosAbierto.value = true;
    }
});

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
    <section
        class="grid gap-5 rounded-xl border border-border bg-card p-4 shadow-xs md:grid-cols-2 md:p-6"
        :aria-labelledby="`titulo-espacio-${indice}`"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 md:col-span-2"
        >
            <h3
                :id="`titulo-espacio-${indice}`"
                class="text-lg font-semibold text-rojo"
            >
                Espacio {{ indice + 1 }}
            </h3>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                title="Copiar este espacio para otra fecha"
                @click="$emit('duplicar')"
            >
                <Copy class="size-4" />
                Duplicar
            </Button>
        </div>

        <CampoFormulario
            :id="`espacio-${indice}`"
            etiqueta="Espacio"
            :error="error('espacio_id')"
        >
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    :id="`buscar-espacio-${indice}`"
                    v-model="filtroEspacio"
                    type="search"
                    class="pl-9"
                    placeholder="Escribe para filtrar espacios…"
                    :aria-label="`Filtrar espacios del espacio ${indice + 1}`"
                />
            </div>
            <CampoSelect
                :id="`espacio-${indice}`"
                v-model="detalle.espacio_id"
                :opciones="opcionesEspacios"
                vacio="Seleccione"
            />
        </CampoFormulario>
        <CampoFormulario
            :id="`docente-${indice}`"
            etiqueta="Responsable del espacio"
            :error="error('docente_responsable_id')"
        >
            <SelectorBuscable
                :id="`docente-${indice}`"
                v-model="detalle.docente_responsable_id"
                :opciones="opcionesDocentes"
                placeholder="Escribe o selecciona al responsable"
                sin-opciones="Primero elige la licenciatura"
            />
            <p class="text-xs text-muted-foreground">
                Persona responsable durante el uso de este espacio (opcional).
            </p>
        </CampoFormulario>

        <CampoFormulario
            :id="`fecha-${indice}`"
            etiqueta="Fecha"
            :error="error('fecha')"
        >
            <!-- Los días antes de la anticipación mínima quedan bloqueados en el selector. -->
            <Input
                :id="`fecha-${indice}`"
                v-model="detalle.fecha"
                type="date"
                class="h-10"
                :min="fechaMinima ?? undefined"
                required
            />
            <p v-if="fechaMinima" class="text-xs text-muted-foreground">
                Disponible a partir del {{ formatearFecha(fechaMinima) }}.
            </p>
            <p
                v-if="avisoAnticipacion"
                class="flex items-start gap-1.5 text-xs text-amber-700"
            >
                <TriangleAlert class="mt-px size-3.5 shrink-0" />
                {{ avisoAnticipacion }}
            </p>
        </CampoFormulario>
        <CampoFormulario
            :id="`inicio-${indice}`"
            etiqueta="Hora inicio"
            :error="error('hora_inicio')"
        >
            <Input
                :id="`inicio-${indice}`"
                v-model="detalle.hora_inicio"
                type="time"
                class="h-10"
                required
            />
        </CampoFormulario>
        <CampoFormulario
            :id="`fin-${indice}`"
            etiqueta="Hora fin"
            :error="error('hora_fin')"
        >
            <Input
                :id="`fin-${indice}`"
                v-model="detalle.hora_fin"
                type="time"
                class="h-10"
                required
            />
        </CampoFormulario>
        <CampoFormulario
            :id="`alumnos-${indice}`"
            etiqueta="Cantidad de alumnos en este espacio *"
            :error="error('cantidad_alumnos') ?? errorAlumnos ?? undefined"
        >
            <Input
                :id="`alumnos-${indice}`"
                v-model.number="detalle.cantidad_alumnos"
                type="number"
                min="1"
                class="h-10"
            />
            <p v-if="ayudaAlumnos" class="text-xs text-muted-foreground">
                {{ ayudaAlumnos }}
            </p>
        </CampoFormulario>

        <!-- Disponibilidad en vivo (informativa) -->
        <div class="md:col-span-2" aria-live="polite">
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

        <!-- Requerimientos del espacio -->
        <div
            class="grid gap-4 rounded-xl border border-border bg-muted/40 p-4 md:col-span-2 md:p-5"
        >
            <div class="grid gap-2">
                <Label class="font-semibold"
                    >Requerimientos disponibles del espacio</Label
                >
                <div class="rounded-lg border border-border bg-background p-3">
                    <p
                        v-if="!espacio"
                        class="inline-block rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground"
                    >
                        Selecciona un espacio para ver sus requerimientos.
                    </p>
                    <p
                        v-else-if="espacio.requerimientos.length === 0"
                        class="inline-block rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground"
                    >
                        Este espacio no tiene requerimientos registrados.
                    </p>
                    <ul v-else class="grid gap-2 sm:grid-cols-2">
                        <li
                            v-for="requerimiento in espacio.requerimientos"
                            :key="requerimiento.id"
                            class="grid gap-1 rounded-md border px-3 py-2 transition-colors"
                            :class="
                                pedido(requerimiento.id)
                                    ? 'border-rojo/40 bg-secondary/60'
                                    : 'border-border'
                            "
                        >
                            <div class="flex flex-wrap items-center gap-3">
                                <Checkbox
                                    :id="`req-${indice}-${requerimiento.id}`"
                                    :model-value="!!pedido(requerimiento.id)"
                                    @update:model-value="
                                        (v) =>
                                            alternarRequerimiento(
                                                requerimiento.id,
                                                v,
                                            )
                                    "
                                />
                                <Label
                                    :for="`req-${indice}-${requerimiento.id}`"
                                    class="min-w-0 flex-1 font-normal"
                                >
                                    {{ requerimiento.nombre }}
                                    <span class="text-xs text-muted-foreground">
                                        (hasta
                                        {{ requerimiento.cantidad_disponible }})
                                    </span>
                                </Label>
                                <Input
                                    v-if="pedido(requerimiento.id)"
                                    :aria-label="`Cantidad de ${requerimiento.nombre}`"
                                    :model-value="
                                        pedido(requerimiento.id)?.cantidad
                                    "
                                    type="number"
                                    min="1"
                                    :max="requerimiento.cantidad_disponible"
                                    class="h-8 w-20"
                                    @update:model-value="
                                        (v) =>
                                            cambiarCantidad(requerimiento.id, v)
                                    "
                                />
                            </div>
                            <InputError
                                :message="errorRequerimiento(requerimiento.id)"
                            />
                        </li>
                    </ul>
                </div>
                <InputError :message="error('requerimientos')" />
            </div>

            <CampoFormulario
                :id="`material-${indice}`"
                etiqueta="Requerimientos adicionales"
                :error="error('material_requerido')"
            >
                <textarea
                    :id="`material-${indice}`"
                    v-model="detalle.material_requerido"
                    :class="textarea"
                    maxlength="5000"
                    placeholder="Escribe aquí algún requerimiento adicional, por ejemplo: extensión eléctrica, mesa de registro, decoración especial…"
                />
            </CampoFormulario>
        </div>

        <!-- Datos que ya existían por espacio: se conservan, plegados para no recargar el formato. -->
        <div class="md:col-span-2">
            <button
                type="button"
                class="flex items-center gap-1.5 text-sm font-medium text-rojo hover:underline"
                :aria-expanded="masDatosAbierto"
                :aria-controls="`mas-datos-${indice}`"
                @click="masDatosAbierto = !masDatosAbierto"
            >
                <ChevronRight
                    class="size-4 transition-transform"
                    :class="{ 'rotate-90': masDatosAbierto }"
                />
                Más datos de este espacio (opcional)
            </button>
            <div
                v-show="masDatosAbierto"
                :id="`mas-datos-${indice}`"
                class="mt-3 grid gap-4 md:grid-cols-2"
            >
                <CampoFormulario
                    :id="`lugar-${indice}`"
                    etiqueta="Lugar específico"
                    :error="error('lugar_especifico')"
                >
                    <Input
                        :id="`lugar-${indice}`"
                        v-model="detalle.lugar_especifico"
                        maxlength="150"
                        placeholder="Por ejemplo: escenario, mesa 3, zona norte"
                    />
                </CampoFormulario>
                <div class="hidden md:block" />
                <CampoFormulario
                    :id="`descripcion-${indice}`"
                    etiqueta="Actividad en este espacio"
                    :error="error('descripcion_actividad')"
                >
                    <textarea
                        :id="`descripcion-${indice}`"
                        v-model="detalle.descripcion_actividad"
                        :class="textarea"
                        maxlength="5000"
                    />
                </CampoFormulario>
                <CampoFormulario
                    :id="`observaciones-${indice}`"
                    etiqueta="Observaciones de este espacio"
                    :error="error('observaciones')"
                >
                    <textarea
                        :id="`observaciones-${indice}`"
                        v-model="detalle.observaciones"
                        :class="textarea"
                        maxlength="5000"
                    />
                </CampoFormulario>
            </div>
        </div>

        <div class="md:col-span-2">
            <Button type="button" @click="$emit('quitar')">
                <Trash2 class="size-4" />
                Eliminar espacio
            </Button>
        </div>
    </section>
</template>
