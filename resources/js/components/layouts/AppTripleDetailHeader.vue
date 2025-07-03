<template>
    <header class="shrink-0 py-2.5 flex items-center space-x-4 px-4">
        <h2 class="text-2xl font-bold text-stone-800">
            {{ detail.name }}
        </h2>
        <AppTripleItemMetaTag :itemMeta="itemMeta" />
    </header>
</template>
<script setup>
import { computed } from "vue";
import AppTripleItemMetaTag from "./AppTripleItemMetaTag.vue";

const { target, detail } = defineProps({
    target: Object,
    detail: Object,
});

const itemMeta = computed(() => ({
    type: target?.title,
    area: target?.item?.parent?.name,
}));
</script>
