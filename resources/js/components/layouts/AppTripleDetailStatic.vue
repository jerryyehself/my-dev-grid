<script setup>
import { computed } from "vue";
import AppTripleDetailStaticCard from "./AppTripleDetailStaticCard.vue";

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
    type: {
        type: String,
        required: true,
    },
});

const childrenCount = computed(() => props.detail?.children?.length || 0);
const siblingsCount = computed(() => props.detail?.siblings?.length || 0);
const relationshipsCount = computed(
    () =>
        (props.detail?.object_of?.length || 0) +
        (props.detail?.subject_of?.length || 0),
);
const recordsCount = computed(() => 0); // 未來可擴充

const staticCards = computed(() => ({
    children: {
        title: "子類數量",
        counter: childrenCount.value,
    },
    siblings: {
        title: "兄弟類數量",
        counter: siblingsCount.value,
    },
    relationships: {
        title: "關聯數量",
        counter: relationshipsCount.value,
    },
    records: {
        title: "記錄數量",
        counter: recordsCount.value,
    },
}));
</script>

<template>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
        <AppTripleDetailStaticCard
            v-if="!props.detail.parent"
            :static-card="staticCards.children"
        />

        <AppTripleDetailStaticCard v-else :static-card="staticCards.siblings" />

        <AppTripleDetailStaticCard
            v-if="props.type === 'scopes'"
            :static-card="staticCards.relationships"
        />

        <AppTripleDetailStaticCard :static-card="staticCards.records" />
    </div>
</template>
