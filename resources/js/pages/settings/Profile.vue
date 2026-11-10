<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Perfil',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Perfil" />

    <h1 class="sr-only">Perfil</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Perfil"
            description="Actualiza tu nombre y teléfono. El correo lo administra el administrador."
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="nombre">Nombre(s)</Label>
                <Input
                    id="nombre"
                    class="mt-1 block w-full"
                    name="nombre"
                    :default-value="user.nombre"
                    required
                    autocomplete="given-name"
                    placeholder="Nombre(s)"
                />
                <InputError class="mt-2" :message="errors.nombre" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="apellido_paterno">Apellido paterno</Label>
                    <Input
                        id="apellido_paterno"
                        class="mt-1 block w-full"
                        name="apellido_paterno"
                        :default-value="user.apellido_paterno"
                        required
                        autocomplete="family-name"
                        placeholder="Apellido paterno"
                    />
                    <InputError
                        class="mt-2"
                        :message="errors.apellido_paterno"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="apellido_materno">Apellido materno</Label>
                    <Input
                        id="apellido_materno"
                        class="mt-1 block w-full"
                        name="apellido_materno"
                        :default-value="user.apellido_materno ?? ''"
                        autocomplete="additional-name"
                        placeholder="Apellido materno (opcional)"
                    />
                    <InputError
                        class="mt-2"
                        :message="errors.apellido_materno"
                    />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="telefono">Teléfono</Label>
                <Input
                    id="telefono"
                    type="tel"
                    class="mt-1 block w-full"
                    name="telefono"
                    :default-value="user.telefono ?? ''"
                    autocomplete="tel"
                    placeholder="Teléfono (opcional)"
                />
                <InputError class="mt-2" :message="errors.telefono" />
            </div>

            <div class="grid gap-2">
                <Label for="correo">Correo electrónico</Label>
                <Input
                    id="correo"
                    type="email"
                    class="mt-1 block w-full"
                    :default-value="user.correo"
                    readonly
                    disabled
                    autocomplete="username"
                />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Guardar</Button
                >
            </div>
        </Form>
    </div>
</template>
