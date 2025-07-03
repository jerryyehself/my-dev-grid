<template>
    <div class="bg-white dark:bg-stone-800 shadow-sm rounded-lg p-4">
        <dt class="text-sm text-gray-500 dark:text-gray-300">
            {{ staticCard.title }}
        </dt>
        <dd class="mt-1 text-xl font-semibold text-stone-700 dark:text-white">
            {{ staticCard.counter }}
        </dd>
    </div>
</template>
<script setup>
defineProps({
    staticCard: Object,
});
</script>
