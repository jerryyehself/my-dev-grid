<template>
    <div class="bg-white dark:bg-stone-800 shadow-sm rounded-lg p-4">
        <dt class="text-sm text-gray-500 dark:text-gray-300">
            {{ props.staticCard.title }}
        </dt>
        <dd class="mt-1 text-xl font-semibold text-stone-700 dark:text-white">
            {{ props.staticCard.counter }}
        </dd>
    </div>
</template>

<script setup>
const props = defineProps({
    staticCard: {
        type: Object,
        required: true,
    },
});
</script>
