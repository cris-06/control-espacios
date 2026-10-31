<script setup lang="ts">
import { Button } from '@/components/ui/button';
</script>

<template>
    <Button
        variant="ghost"
        size="icon"
        class="relative shrink-0 hover:bg-white/15 hover:text-white focus-visible:ring-white/60"
    >
        <slot />
    </Button>
</template>
