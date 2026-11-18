<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { CircleCheck, CircleX, Eye, Pencil, Send, Undo2 } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { edit, show, transicion } from '@/routes/reservas';
import type { AccionesFila, TransicionFila } from '@/types';

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
};

const seleccionada = ref<TransicionFila | null>(null);
const form = useForm({ accion: '', observacion: '' });

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
</script>

<template>
    <div
        class="grid min-w-36 gap-1.5 [&_a]:justify-start [&_a]:whitespace-nowrap [&_button]:justify-start [&_button]:whitespace-nowrap"
    >
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
            v-for="accion in acciones.transiciones"
            :key="accion.accion"
            :variant="ESTILOS[accion.accion].variante"
            size="sm"
            :aria-label="`${accion.etiqueta} ${folio}`"
            @click="abrir(accion)"
        >
            <component :is="ESTILOS[accion.accion].icono" class="size-4" />
            {{ accion.etiqueta }}
        </Button>
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
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                        :placeholder="
                            seleccionada.requiereObservacion
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
                    <Button
                        type="button"
                        variant="outline"
                        @click="seleccionada = null"
                        >Cancelar</Button
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
</template>
