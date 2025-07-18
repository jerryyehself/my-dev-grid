<template>
    <header class="shrink-0 py-2.5 flex items-center space-x-4 px-4">
        <h2 class="text-2xl font-bold text-stone-800">
            {{ props.detail.name }}
        </h2>
        <AppTripleItemMetaTag :itemMeta="itemMeta" />
    </header>
</template>

<script setup>
import { computed } from "vue";
import AppTripleItemMetaTag from "./AppTripleItemMetaTag.vue";

const props = defineProps({
    target: {
        type: Object,
        required: true,
    },
    detail: {
        type: Object,
        required: true,
    },
});

const itemMeta = computed(() => ({
    type: props.target?.title,
    area: props.target?.item?.parent?.name,
}));
</script>
