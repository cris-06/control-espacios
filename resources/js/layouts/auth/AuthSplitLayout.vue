<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="grid min-h-dvh lg:grid-cols-[5fr_6fr]">
        <div
            class="hidden flex-col items-center justify-center gap-6 border-r-[3px] border-primary bg-rojo p-10 text-center text-rojo-foreground lg:flex"
        >
            <Link :href="home()" class="rounded-2xl bg-white p-4 shadow-lg">
                <AppLogoIcon class="size-52 object-contain" />
            </Link>
            <div class="space-y-1">
                <p class="text-2xl font-semibold tracking-tight">
                    Sistema de reservas
                </p>
                <p class="text-white">Centro Universitario de Valladolid</p>
            </div>
        </div>
        <div class="flex items-center justify-center bg-background p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <Link :href="home()" class="mx-auto lg:hidden">
                    <AppLogoIcon class="size-28 object-contain" />
                </Link>
                <div class="space-y-1 text-center lg:text-left">
                    <h1
                        v-if="title"
                        class="text-2xl font-semibold tracking-tight text-rojo"
                    >
                        {{ title }}
                    </h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
