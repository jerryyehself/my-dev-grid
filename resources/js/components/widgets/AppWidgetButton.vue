<template>
    <button
        :class="[
            'inline-flex items-center justify-center gap-1 cursor-pointer rounded-sm px-2 py-1 text-white hover:shadow-lg transition-transform duration-200 hover:scale-105',
            button.color,
        ]"
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
