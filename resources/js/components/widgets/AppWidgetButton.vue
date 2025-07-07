<template>
    <button
        :class="[
            'inline-flex items-center justify-center gap-1 rounded-sm px-2 py-1 text-white transition-transform duration-200',
            button.color,
            button.ability.value
                ? 'hover:scale-105 cursor-pointer'
                : 'opacity-50 cursor-not-allowed',
        ]"
        :disabled="!button.ability.value"
        :action="button.action"
    >
        <component :is="button.icon" :class="button.iconClass" />
        <span class="text-sm font-medium tracking-wide">
            {{ button.label }}
        </span>
    </button>
</template>
<script setup>
defineProps({
    button: Object,
});
</script>
