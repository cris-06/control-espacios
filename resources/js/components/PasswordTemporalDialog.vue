<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PasswordTemporalFlash } from '@/types';

const page = usePage();
const abierto = ref(false);
const datos = ref<PasswordTemporalFlash | null>(null);
const { copy, copied } = useClipboard({ legacy: true });

// El servidor la envía como flash: solo llega en la respuesta que la generó.
watch(
    () => page.flash?.passwordTemporal,
    (valor) => {
        if (valor) {
            datos.value = valor;
            abierto.value = true;
        }
    },
    { immediate: true },
);

watch(abierto, (valor) => {
    if (!valor) {
        datos.value = null;
    }
});
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent v-if="datos">
            <DialogHeader>
                <DialogTitle>Contraseña temporal</DialogTitle>
                <DialogDescription>
                    Entrégala a {{ datos.name }} ({{ datos.correo }}). Solo se
                    muestra esta vez; al iniciar sesión se le pedirá cambiarla.
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex items-center justify-between gap-3 rounded-md border border-border bg-muted px-4 py-3"
            >
                <code
                    class="font-mono text-lg tracking-wider select-all"
                    data-test="password-temporal"
                    >{{ datos.password }}</code
                >
                <Button
                    variant="outline"
                    size="sm"
                    @click="copy(datos.password)"
                >
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? 'Copiada' : 'Copiar' }}
                </Button>
            </div>

            <DialogFooter>
                <Button @click="abierto = false">Listo</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
