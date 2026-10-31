<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
    compacto?: boolean;
}>();

const { isCurrentUrl } = useCurrentUrl();
const { setOpenMobile } = useSidebar();
</script>

<template>
    <nav aria-label="Menú principal">
        <ul class="flex flex-col gap-0.5">
            <li v-for="item in items" :key="item.title">
                <Link
                    :href="item.href"
                    class="relative flex h-9 items-center gap-4 overflow-hidden text-sm whitespace-nowrap transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                    :class="[
                        compacto
                            ? 'mx-3 rounded-full pl-3.5'
                            : 'mr-4 rounded-r-full pl-[1.625rem]',
                        isCurrentUrl(item.href)
                            ? 'bg-accent font-semibold text-accent-foreground'
                            : 'hover:bg-muted',
                    ]"
                    :aria-current="isCurrentUrl(item.href) ? 'page' : undefined"
                    @click="setOpenMobile(false)"
                >
                    <component :is="item.icon" class="size-5 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">{{
                        item.title
                    }}</span>
                    <span
                        v-if="item.conteo"
                        :class="
                            compacto
                                ? 'sr-only'
                                : 'pr-4 text-xs font-semibold tabular-nums'
                        "
                    >
                        {{ item.conteo }}
                    </span>
                    <span
                        v-if="item.conteo && compacto"
                        class="absolute top-1.5 left-[1.875rem] size-2 rounded-full bg-rojo"
                        aria-hidden="true"
                    ></span>
                </Link>
            </li>
        </ul>
    </nav>
</template>
