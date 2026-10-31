<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="min-w-0 overflow-x-clip pt-16 print:pt-0"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="relative flex flex-1 flex-col">
                <div
                    class="pointer-events-none absolute inset-0 print:hidden"
                    aria-hidden="true"
                >
                    <div
                        class="sticky top-16 flex h-[calc(100svh-4rem)] items-center justify-center p-8"
                    >
                        <img
                            src="/images/logo_cuv.png"
                            alt=""
                            class="max-h-full w-full max-w-xl object-contain opacity-[0.05]"
                        />
                    </div>
                </div>
                <div class="relative flex flex-1 flex-col">
                    <slot />
                </div>
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
