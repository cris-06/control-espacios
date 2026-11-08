<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import CambiarPasswordController from '@/actions/App/Http/Controllers/Settings/CambiarPasswordController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

defineOptions({
    layout: {
        title: 'Cambia tu contraseña',
        description:
            'Entraste con una contraseña temporal. Elige una nueva para continuar.',
    },
});

defineProps<{
    passwordRules: string;
}>();
</script>

<template>
    <Head title="Cambiar contraseña" />

    <Form
        v-bind="CambiarPasswordController.update.form()"
        :reset-on-error="['password', 'password_confirmation', 'current_password']"
        class="flex flex-col gap-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="current_password">Contraseña temporal</Label>
            <PasswordInput
                id="current_password"
                name="current_password"
                required
                v-focus
                autocomplete="current-password"
                placeholder="La que te dio administración"
            />
            <InputError :message="errors.current_password" />
        </div>

        <div class="grid gap-2">
            <Label for="password">Nueva contraseña</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="new-password"
                placeholder="Nueva contraseña"
                :passwordrules="passwordRules"
            />
            <InputError :message="errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">Confirma la nueva contraseña</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
                placeholder="Repite la nueva contraseña"
                :passwordrules="passwordRules"
            />
            <InputError :message="errors.password_confirmation" />
        </div>

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Guardar y continuar
        </Button>
    </Form>

    <div class="mt-6 text-center text-sm text-muted-foreground">
        <Link
            :href="logout()"
            as="button"
            type="button"
            class="underline underline-offset-4"
            >Cerrar sesión</Link
        >
    </div>
</template>
