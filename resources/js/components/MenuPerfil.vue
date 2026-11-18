<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BotonEncabezado from '@/components/BotonEncabezado.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';
import { etiquetaRol } from '@/lib/reservas';

const page = usePage();
const user = computed(() => page.props.auth.user);
const rol = computed(() => etiquetaRol(page.props.auth.rol?.nombre));
const { getInitials } = useInitials();
</script>

<template>
    <span
        v-if="rol"
        class="mr-1 hidden max-w-48 truncate rounded-full bg-white/15 px-2.5 py-1 text-xs font-medium ring-1 ring-white/40 sm:inline-block md:max-w-none"
        data-test="rol-usuario"
    >
        {{ rol }}
    </span>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <BotonEncabezado
                aria-label="Perfil"
                data-test="sidebar-menu-button"
            >
                <Avatar class="size-8 overflow-hidden rounded-full">
                    <AvatarImage
                        v-if="user.avatar"
                        :src="user.avatar"
                        :alt="user.name"
                    />
                    <AvatarFallback
                        class="rounded-full bg-white font-semibold text-rojo"
                    >
                        {{ getInitials(user.name) }}
                    </AvatarFallback>
                </Avatar>
            </BotonEncabezado>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <UserMenuContent :user="user" />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
