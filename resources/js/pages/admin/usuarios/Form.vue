<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UsuarioController from '@/actions/App/Http/Controllers/Admin/UsuarioController';
import CampoFormulario from '@/components/CampoFormulario.vue';
import InputError from '@/components/InputError.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/admin/usuarios';
import type { LicenciaturaFila, RolAsignable, UsuarioFormulario } from '@/types';

const props = defineProps<{
    usuario: UsuarioFormulario | null;
    roles: RolAsignable[];
    licenciaturas: LicenciaturaFila[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usuarios', href: index() }],
    },
});

const esEdicion = computed(() => props.usuario !== null);
const titulo = computed(() =>
    esEdicion.value ? 'Editar usuario' : 'Nuevo usuario',
);

const rolId = ref<string>(
    props.usuario ? String(props.usuario.rol_id) : '',
);
const rolSeleccionado = computed(() =>
    props.roles.find((rol) => String(rol.id) === rolId.value),
);
const asignadas = computed(
    () => new Set(props.usuario?.licenciaturas ?? []),
);

const accion = computed(() =>
    props.usuario
        ? UsuarioController.update.form(props.usuario.id)
        : UsuarioController.store.form(),
);

const nombreRol = (nombre: string) =>
    nombre.charAt(0).toUpperCase() + nombre.slice(1);
</script>

<template>
    <Head :title="titulo" />

    <PaginaBase
        :titulo="titulo"
        :descripcion="
            esEdicion
                ? 'Actualiza los datos, el rol y las licenciaturas del usuario.'
                : 'Se generará una contraseña temporal que el usuario deberá cambiar al entrar.'
        "
    >
        <TarjetaSeccion>
            <Form
                v-bind="accion"
                class="grid gap-6 p-4 md:p-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-4 md:grid-cols-3">
                    <CampoFormulario
                        id="nombre"
                        etiqueta="Nombre(s)"
                        :error="errors.nombre"
                    >
                        <Input
                            id="nombre"
                            name="nombre"
                            :default-value="usuario?.nombre ?? ''"
                            required
                            maxlength="100"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="apellido_paterno"
                        etiqueta="Apellido paterno"
                        :error="errors.apellido_paterno"
                    >
                        <Input
                            id="apellido_paterno"
                            name="apellido_paterno"
                            :default-value="usuario?.apellido_paterno ?? ''"
                            required
                            maxlength="100"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="apellido_materno"
                        etiqueta="Apellido materno (opcional)"
                        :error="errors.apellido_materno"
                    >
                        <Input
                            id="apellido_materno"
                            name="apellido_materno"
                            :default-value="usuario?.apellido_materno ?? ''"
                            maxlength="100"
                        />
                    </CampoFormulario>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <CampoFormulario
                        id="correo"
                        etiqueta="Correo institucional"
                        :error="errors.correo"
                    >
                        <Input
                            id="correo"
                            name="correo"
                            type="email"
                            :default-value="usuario?.correo ?? ''"
                            required
                            maxlength="150"
                            autocomplete="off"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="telefono"
                        etiqueta="Teléfono (opcional)"
                        :error="errors.telefono"
                    >
                        <Input
                            id="telefono"
                            name="telefono"
                            type="tel"
                            :default-value="usuario?.telefono ?? ''"
                            maxlength="20"
                        />
                    </CampoFormulario>
                </div>

                <CampoFormulario id="rol_id" etiqueta="Rol" :error="errors.rol_id">
                    <!-- El propio rol no se cambia: el campo se muestra bloqueado y se envía oculto. -->
                    <input
                        v-if="usuario?.esYoMismo"
                        type="hidden"
                        name="rol_id"
                        :value="usuario.rol_id"
                    />
                    <select
                        id="rol_id"
                        v-model="rolId"
                        :name="usuario?.esYoMismo ? undefined : 'rol_id'"
                        :disabled="usuario?.esYoMismo"
                        required
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-60 md:w-80 md:text-sm"
                    >
                        <option value="" disabled>Selecciona un rol</option>
                        <option
                            v-for="rol in roles"
                            :key="rol.id"
                            :value="String(rol.id)"
                        >
                            {{ nombreRol(rol.nombre) }}
                        </option>
                    </select>
                    <p
                        v-if="usuario?.esYoMismo"
                        class="text-xs text-muted-foreground"
                    >
                        No puedes cambiar tu propio rol.
                    </p>
                </CampoFormulario>

                <fieldset
                    v-if="rolSeleccionado?.usaLicenciaturas"
                    class="grid gap-2"
                >
                    <legend class="mb-1 text-sm font-medium">
                        Licenciaturas
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        Un docente o coordinador puede tener varias. El
                        coordinador revisa las reservas de sus licenciaturas.
                    </p>
                    <p
                        v-if="licenciaturas.length === 0"
                        class="text-sm text-amber-700"
                    >
                        No hay licenciaturas activas. Créalas primero en
                        Administración → Licenciaturas.
                    </p>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="licenciatura in licenciaturas"
                            :key="licenciatura.id"
                            class="flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                name="licenciaturas[]"
                                :value="licenciatura.id"
                                :checked="asignadas.has(licenciatura.id)"
                                class="size-4 accent-rojo"
                            />
                            <span>{{ licenciatura.nombre }}</span>
                            <span
                                v-if="licenciatura.estatus !== 'activa'"
                                class="text-xs text-muted-foreground"
                                >(inactiva)</span
                            >
                        </label>
                    </div>
                    <InputError
                        :message="
                            errors.licenciaturas ??
                            Object.entries(errors).find(([clave]) =>
                                clave.startsWith('licenciaturas.'),
                            )?.[1]
                        "
                    />
                </fieldset>

                <div class="flex flex-wrap gap-3">
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ esEdicion ? 'Guardar cambios' : 'Crear usuario' }}
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="index()">Cancelar</Link>
                    </Button>
                </div>
            </Form>
        </TarjetaSeccion>
    </PaginaBase>
</template>
