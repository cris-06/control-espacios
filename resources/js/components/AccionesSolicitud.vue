<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import {
    Ban,
    CalendarClock,
    ChevronDown,
    CircleCheck,
    CircleX,
    Eye,
    Pencil,
    Send,
    Undo2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatearFecha } from '@/lib/reservas';
import { edit, reprogramar, show, transicion } from '@/routes/reservas';
import type {
    AccionesFila,
    BloqueReprogramable,
    Opcion,
    TransicionFila,
} from '@/types';

// Botones de la columna ACCIONES del listado de solicitudes. El servidor decide cuáles
// llegan (política y estado actual); al ejecutarlas, Laravel vuelve a autorizar.
const props = defineProps<{
    reservaId: number;
    folio: string;
    acciones: AccionesFila;
}>();

const ESTILOS: Record<
    TransicionFila['accion'],
    {
        icono: Component;
        variante: 'default' | 'destructive' | 'outline';
        descripcion: string;
    }
> = {
    aprobar: {
        icono: Send,
        variante: 'default',
        descripcion:
            'La solicitud pasará a revisión de administración (directivo).',
    },
    autorizar: {
        icono: CircleCheck,
        variante: 'default',
        descripcion:
            'Se comprobarán de nuevo la disponibilidad y los choques con reservas autorizadas antes de autorizar.',
    },
    denegar: {
        icono: CircleX,
        variante: 'destructive',
        descripcion: 'La decisión es definitiva y la verá el solicitante.',
    },
    solicitar_correccion: {
        icono: Undo2,
        variante: 'outline',
        descripcion: 'La solicitud regresará al solicitante para corregirla.',
    },
    cancelar: {
        icono: Ban,
        variante: 'destructive',
        descripcion:
            'La reserva autorizada quedará cancelada y el espacio y horario quedarán libres. Se notificará al solicitante y a coordinación.',
    },
    enviar_edicion: {
        icono: Undo2,
        variante: 'outline',
        descripcion:
            'La reserva dejará de estar autorizada y regresará al solicitante para editarla y reenviarla.',
    },
};

// Orden en la fila: Ver, Editar, transiciones, Reprogramar y, al final, Enviar a edición.
// Las acciones sobre una reserva autorizada (cancelar, reprogramar y enviar a edición) van
// agrupadas en el menú «Acciones»; las del flujo pendiente siguen como botones.
const EN_MENU: TransicionFila['accion'][] = ['cancelar', 'enviar_edicion'];
const transicionesBotones = computed(() =>
    props.acciones.transiciones.filter((a) => !EN_MENU.includes(a.accion)),
);
const enviarEdicion = computed(() =>
    props.acciones.transiciones.find((a) => a.accion === 'enviar_edicion'),
);
const cancelar = computed(() =>
    props.acciones.transiciones.find((a) => a.accion === 'cancelar'),
);

// --- Transiciones (diálogo con observación) ---
const seleccionada = ref<TransicionFila | null>(null);
// origen = listado: el servidor regresa al listado (refrescado) en lugar de ir al detalle.
const form = useForm({ accion: '', observacion: '', origen: 'listado' });

const dialogoAbierto = computed({
    get: () => seleccionada.value !== null,
    set: (valor) => {
        if (!valor) {
            seleccionada.value = null;
        }
    },
});

// Errores del servidor que no son de la observación (traslapes, validaciones, estado).
const otrosErrores = computed(() =>
    Object.entries(form.errors)
        .filter(([campo]) => campo !== 'observacion')
        .map(([, mensaje]) => mensaje),
);

const abrir = (accion: TransicionFila) => {
    form.clearErrors();
    form.accion = accion.accion;
    form.observacion = '';
    seleccionada.value = accion;
};

const confirmar = () => {
    form.post(transicion.url(props.reservaId), {
        preserveScroll: true,
        onSuccess: () => {
            seleccionada.value = null;
        },
    });
};

// --- Reprogramar (diálogo con fecha, horario, espacio y motivo) ---
const page = usePage<{ opcionesEspacios?: Opcion[] }>();
const reprogramando = ref(false);
const reprogramacion = useForm({
    detalle_id: '',
    espacio_id: '' as string | null,
    fecha: '',
    hora_inicio: '',
    hora_fin: '',
    motivo: '',
});

const bloques = computed<BloqueReprogramable[]>(
    () => props.acciones.reprogramar ?? [],
);
const opcionesBloques = computed<Opcion[]>(() =>
    bloques.value.map((b) => ({
        id: b.id,
        nombre: `${b.espacio} · ${formatearFecha(b.fecha)} · ${b.hora_inicio} a ${b.hora_fin}`,
    })),
);

// Espacios disponibles; el actual del bloque se agrega si no está en la lista.
const opcionesEspacios = computed<Opcion[]>(() => {
    const espacios = page.props.opcionesEspacios ?? [];
    const actual = bloques.value.find(
        (b) => String(b.id) === reprogramacion.detalle_id,
    );

    return actual && !espacios.some((e) => e.id === actual.espacio_id)
        ? [{ id: actual.espacio_id, nombre: actual.espacio }, ...espacios]
        : espacios;
});

const hoy = () => {
    const ahora = new Date();
    const mes = String(ahora.getMonth() + 1).padStart(2, '0');
    const dia = String(ahora.getDate()).padStart(2, '0');

    return `${ahora.getFullYear()}-${mes}-${dia}`;
};

const CAMPOS_REPROGRAMAR = [
    'detalle_id',
    'espacio_id',
    'fecha',
    'hora_inicio',
    'hora_fin',
    'motivo',
];

// Choques con otras reservas autorizadas (reserva.N) y demás errores sin campo propio.
const erroresReprogramar = computed(() =>
    Object.entries(reprogramacion.errors)
        .filter(([campo]) => !CAMPOS_REPROGRAMAR.includes(campo))
        .map(([, mensaje]) => mensaje),
);

// Precarga los datos actuales del bloque elegido.
const cargarBloque = (id: string | number | null) => {
    const bloque = bloques.value.find((b) => String(b.id) === String(id));

    if (!bloque) {
        return;
    }

    reprogramacion.detalle_id = String(bloque.id);
    reprogramacion.espacio_id = String(bloque.espacio_id);
    reprogramacion.fecha = bloque.fecha;
    reprogramacion.hora_inicio = bloque.hora_inicio;
    reprogramacion.hora_fin = bloque.hora_fin;
};

const bloqueSeleccionado = computed({
    get: () => reprogramacion.detalle_id || null,
    set: (valor) => cargarBloque(valor),
});

const abrirReprogramar = () => {
    reprogramacion.clearErrors();
    reprogramacion.motivo = '';
    cargarBloque(bloques.value[0]?.id ?? null);
    reprogramando.value = true;
};

const confirmarReprogramar = () => {
    reprogramacion.patch(reprogramar.url(props.reservaId), {
        preserveScroll: true,
        onSuccess: () => {
            reprogramando.value = false;
        },
    });
};

// --- Menú «Acciones» ---
const hayMenu = computed(
    () =>
        bloques.value.length > 0 ||
        enviarEdicion.value !== undefined ||
        cancelar.value !== undefined,
);

// La opción elegida se guarda aquí y el diálogo se abre cuando el menú ya terminó de
// cerrarse (close-auto-focus): así el menú no le devuelve el foco al botón ni deja el
// body bloqueado encima del diálogo.
const pendiente = ref<(() => void) | null>(null);

const elegir = (abrirDialogo: () => void) => {
    pendiente.value = abrirDialogo;
};

const alCerrarMenu = (evento: Event) => {
    const abrirDialogo = pendiente.value;

    if (abrirDialogo) {
        evento.preventDefault();
        pendiente.value = null;
        abrirDialogo();
    }
};

const claseTextarea =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <div class="flex flex-nowrap items-center gap-2 whitespace-nowrap">
        <Button v-if="acciones.ver" variant="outline" size="sm" as-child>
            <Link :href="show(reservaId)" :aria-label="`Ver ${folio}`">
                <Eye class="size-4" />
                Ver
            </Link>
        </Button>
        <Button v-if="acciones.editar" variant="outline" size="sm" as-child>
            <Link :href="edit(reservaId)" :aria-label="`Editar ${folio}`">
                <Pencil class="size-4" />
                Editar
            </Link>
        </Button>
        <Button
            v-for="accion in transicionesBotones"
            :key="accion.accion"
            :variant="ESTILOS[accion.accion].variante"
            size="sm"
            :aria-label="`${accion.etiqueta} ${folio}`"
            @click="abrir(accion)"
        >
            <component :is="ESTILOS[accion.accion].icono" class="size-4" />
            {{ accion.etiqueta }}
        </Button>
        <DropdownMenu v-if="hayMenu">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="outline"
                    size="sm"
                    :aria-label="`Más acciones de ${folio}`"
                >
                    Acciones
                    <ChevronDown class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                class="w-48"
                @close-auto-focus="alCerrarMenu"
            >
                <DropdownMenuItem
                    v-if="bloques.length"
                    @select="elegir(abrirReprogramar)"
                >
                    <CalendarClock />
                    Reprogramar
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="enviarEdicion"
                    @select="
                        elegir(() => enviarEdicion && abrir(enviarEdicion))
                    "
                >
                    <Undo2 />
                    {{ enviarEdicion.etiqueta }}
                </DropdownMenuItem>
                <template v-if="cancelar">
                    <DropdownMenuSeparator
                        v-if="bloques.length || enviarEdicion"
                    />
                    <DropdownMenuItem
                        variant="destructive"
                        @select="elegir(() => cancelar && abrir(cancelar))"
                    >
                        <Ban />
                        Cancelar reserva
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>

    <Dialog v-model:open="dialogoAbierto">
        <DialogContent v-if="seleccionada">
            <DialogHeader>
                <DialogTitle
                    >{{ seleccionada.etiqueta }} · {{ folio }}</DialogTitle
                >
                <DialogDescription>
                    {{ ESTILOS[seleccionada.accion].descripcion }}
                    {{
                        seleccionada.requiereObservacion
                            ? 'Escribe una observación: es obligatoria y la verá el solicitante.'
                            : 'Puedes agregar un comentario opcional.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="confirmar">
                <div class="grid gap-1.5">
                    <textarea
                        v-model="form.observacion"
                        :required="seleccionada.requiereObservacion"
                        maxlength="5000"
                        rows="4"
                        :class="claseTextarea"
                        :placeholder="
                            seleccionada.accion === 'cancelar'
                                ? 'Motivo de la cancelación'
                                : seleccionada.accion === 'enviar_edicion'
                                  ? 'Qué debe corregir el solicitante'
                                  : seleccionada.requiereObservacion
                                    ? 'Observación'
                                    : 'Comentario (opcional)'
                        "
                    />
                    <InputError :message="form.errors.observacion" />
                </div>
                <ul
                    v-if="otrosErrores.length"
                    class="grid gap-1 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                    role="alert"
                >
                    <li v-for="(mensaje, i) in otrosErrores" :key="i">
                        {{ mensaje }}
                    </li>
                </ul>
                <DialogFooter>
                    <!-- «Volver» en lugar de «Cancelar» para no confundirlo con la acción. -->
                    <Button
                        type="button"
                        variant="outline"
                        @click="seleccionada = null"
                        >{{
                            seleccionada.accion === 'cancelar'
                                ? 'Volver'
                                : 'Cancelar'
                        }}</Button
                    >
                    <Button
                        type="submit"
                        :variant="ESTILOS[seleccionada.accion].variante"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        Confirmar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="reprogramando">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Reprogramar · {{ folio }}</DialogTitle>
                <DialogDescription>
                    Cambia la fecha, el horario o el espacio. La reserva sigue
                    autorizada; si choca con otra reserva autorizada no se
                    guardará. Se notificará al solicitante.
                </DialogDescription>
            </DialogHeader>
            <form
                class="grid gap-4 sm:grid-cols-2"
                @submit.prevent="confirmarReprogramar"
            >
                <CampoFormulario
                    v-if="bloques.length > 1"
                    :id="`bloque-${reservaId}`"
                    etiqueta="Bloque"
                    :error="reprogramacion.errors.detalle_id"
                    class="sm:col-span-2"
                >
                    <CampoSelect
                        :id="`bloque-${reservaId}`"
                        v-model="bloqueSeleccionado"
                        :opciones="opcionesBloques"
                        vacio="Selecciona un bloque"
                    />
                </CampoFormulario>
                <InputError
                    v-else
                    class="sm:col-span-2"
                    :message="reprogramacion.errors.detalle_id"
                />
                <CampoFormulario
                    :id="`espacio-${reservaId}`"
                    etiqueta="Espacio"
                    :error="reprogramacion.errors.espacio_id"
                    class="sm:col-span-2"
                >
                    <CampoSelect
                        :id="`espacio-${reservaId}`"
                        v-model="reprogramacion.espacio_id"
                        :opciones="opcionesEspacios"
                        vacio="Selecciona un espacio"
                    />
                </CampoFormulario>
                <CampoFormulario
                    :id="`fecha-${reservaId}`"
                    etiqueta="Fecha"
                    :error="reprogramacion.errors.fecha"
                    class="sm:col-span-2"
                >
                    <Input
                        :id="`fecha-${reservaId}`"
                        v-model="reprogramacion.fecha"
                        type="date"
                        :min="hoy()"
                        required
                    />
                </CampoFormulario>
                <CampoFormulario
                    :id="`inicio-${reservaId}`"
                    etiqueta="Hora de inicio"
                    :error="reprogramacion.errors.hora_inicio"
                >
                    <Input
                        :id="`inicio-${reservaId}`"
                        v-model="reprogramacion.hora_inicio"
                        type="time"
                        required
                    />
                </CampoFormulario>
                <CampoFormulario
                    :id="`fin-${reservaId}`"
                    etiqueta="Hora de fin"
                    :error="reprogramacion.errors.hora_fin"
                >
                    <Input
                        :id="`fin-${reservaId}`"
                        v-model="reprogramacion.hora_fin"
                        type="time"
                        required
                    />
                </CampoFormulario>
                <div class="grid gap-1.5 sm:col-span-2">
                    <textarea
                        v-model="reprogramacion.motivo"
                        required
                        maxlength="5000"
                        rows="3"
                        :class="claseTextarea"
                        placeholder="Motivo de la reprogramación"
                        aria-label="Motivo de la reprogramación"
                    />
                    <InputError :message="reprogramacion.errors.motivo" />
                </div>
                <ul
                    v-if="erroresReprogramar.length"
                    class="grid gap-1 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive sm:col-span-2"
                    role="alert"
                >
                    <li v-for="(mensaje, i) in erroresReprogramar" :key="i">
                        {{ mensaje }}
                    </li>
                </ul>
                <DialogFooter class="sm:col-span-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="reprogramando = false"
                        >Volver</Button
                    >
                    <Button type="submit" :disabled="reprogramacion.processing">
                        <Spinner v-if="reprogramacion.processing" />
                        Reprogramar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
