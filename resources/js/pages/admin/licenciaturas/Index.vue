<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Pencil, Power } from '@lucide/vue';
import { computed, ref } from 'vue';
import LicenciaturaController from '@/actions/App/Http/Controllers/Admin/LicenciaturaController';
import CampoFormulario from '@/components/CampoFormulario.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TablaDatos from '@/components/TablaDatos.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { estatus, index } from '@/routes/admin/licenciaturas';
import type { LicenciaturaFila } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Licenciaturas', href: index() }],
    },
});

const props = defineProps<{
    licenciaturas: LicenciaturaFila[];
}>();

const columnas = [
    { clave: 'nombre', titulo: 'Licenciatura' },
    { clave: 'siglas', titulo: 'Siglas' },
    { clave: 'asignados', titulo: 'Asignados' },
    { clave: 'estatus', titulo: 'Estatus' },
    { clave: 'acciones', titulo: 'Acciones' },
];

const conteo = computed(() =>
    props.licenciaturas.length === 1
        ? '1 licenciatura'
        : `${props.licenciaturas.length} licenciaturas`,
);

const editando = ref<LicenciaturaFila | null>(null);
const dialogoAbierto = computed({
    get: () => editando.value !== null,
    set: (valor) => {
        if (!valor) {
            editando.value = null;
        }
    },
});

const cambiarEstatus = (licenciatura: LicenciaturaFila) => {
    const accion = licenciatura.estatus === 'activa' ? 'inactivar' : 'activar';

    if (
        !window.confirm(`¿Seguro que quieres ${accion} ${licenciatura.nombre}?`)
    ) {
        return;
    }

    router.patch(estatus.url(licenciatura.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Licenciaturas" />

    <PaginaBase
        titulo="Licenciaturas"
        descripcion="Catálogo de licenciaturas. Una licenciatura inactiva no se puede asignar a nuevos usuarios."
    >
        <TarjetaSeccion
            titulo="Nueva licenciatura"
            descripcion="Las siglas oficiales (2 a 10 letras) forman el folio institucional de las reservas que se creen después de registrarlas. Déjalas vacías hasta que el CUV las confirme."
        >
            <Form
                v-bind="LicenciaturaController.store.form()"
                reset-on-success
                :options="{ preserveScroll: true }"
                class="flex flex-wrap items-end gap-3 p-4"
                v-slot="{ errors, processing }"
            >
                <div class="w-full sm:w-96">
                    <CampoFormulario
                        id="nombre-nueva"
                        etiqueta="Nombre"
                        :error="errors.nombre"
                    >
                        <Input
                            id="nombre-nueva"
                            name="nombre"
                            required
                            maxlength="100"
                            placeholder="Ej. Sistemas Computacionales"
                        />
                    </CampoFormulario>
                </div>
                <div class="w-full sm:w-40">
                    <CampoFormulario
                        id="siglas-nueva"
                        etiqueta="Siglas (opcional)"
                        :error="errors.siglas"
                    >
                        <Input
                            id="siglas-nueva"
                            name="siglas"
                            maxlength="10"
                            autocomplete="off"
                            class="uppercase"
                        />
                    </CampoFormulario>
                </div>
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Agregar
                </Button>
            </Form>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Listado" :descripcion="conteo">
            <TablaDatos
                :columnas="columnas"
                :filas="licenciaturas"
                vacio="Aún no hay licenciaturas."
            >
                <template #asignados="{ fila }">
                    <span class="text-sm text-muted-foreground">
                        {{ fila.coordinadores_count ?? 0 }} coordinador(es) ·
                        {{ fila.docentes_count ?? 0 }} docente(s)
                    </span>
                    <div
                        v-if="
                            fila.estatus === 'activa' &&
                            (fila.coordinadores_count ?? 0) === 0
                        "
                        class="text-xs text-amber-700"
                    >
                        Sin coordinador: sus docentes no podrán enviar reservas.
                    </div>
                </template>
                <template #siglas="{ fila }">
                    <span v-if="fila.siglas" class="font-medium">{{
                        fila.siglas
                    }}</span>
                    <span v-else class="text-sm text-muted-foreground italic"
                        >Sin siglas</span
                    >
                </template>
                <template #estatus="{ fila }">
                    <Badge
                        variant="outline"
                        :class="
                            fila.estatus === 'activa'
                                ? 'border-green-200 bg-green-50 text-green-800'
                                : 'border-border bg-muted text-muted-foreground'
                        "
                    >
                        {{ fila.estatus === 'activa' ? 'Activa' : 'Inactiva' }}
                    </Badge>
                </template>
                <template #acciones="{ fila }">
                    <div class="flex flex-wrap gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="editando = fila"
                        >
                            <Pencil class="size-4" />
                            Editar
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="cambiarEstatus(fila)"
                        >
                            <Power class="size-4" />
                            {{
                                fila.estatus === 'activa'
                                    ? 'Inactivar'
                                    : 'Activar'
                            }}
                        </Button>
                    </div>
                </template>
            </TablaDatos>
        </TarjetaSeccion>
    </PaginaBase>

    <Dialog v-model:open="dialogoAbierto">
        <DialogContent v-if="editando">
            <DialogHeader>
                <DialogTitle>Editar licenciatura</DialogTitle>
            </DialogHeader>
            <Form
                v-bind="LicenciaturaController.update.form(editando.id)"
                :options="{ preserveScroll: true }"
                class="grid gap-4"
                @success="editando = null"
                v-slot="{ errors, processing }"
            >
                <CampoFormulario
                    id="nombre-editar"
                    etiqueta="Nombre"
                    :error="errors.nombre"
                >
                    <Input
                        id="nombre-editar"
                        name="nombre"
                        :default-value="editando.nombre"
                        required
                        maxlength="100"
                    />
                </CampoFormulario>
                <CampoFormulario
                    id="siglas-editar"
                    etiqueta="Siglas (opcional)"
                    :error="errors.siglas"
                >
                    <Input
                        id="siglas-editar"
                        name="siglas"
                        :default-value="editando.siglas ?? ''"
                        maxlength="10"
                        autocomplete="off"
                        class="uppercase"
                    />
                    <p class="text-xs text-muted-foreground">
                        Cambiarlas solo afecta a las reservas nuevas; los folios
                        ya asignados no cambian.
                    </p>
                </CampoFormulario>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="editando = null"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
