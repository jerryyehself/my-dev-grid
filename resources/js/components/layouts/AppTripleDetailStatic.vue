<template>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
        <!-- 子類數量 -->

        <AppTripleDetailStaticCard
            v-if="!detail.parent"
            :static-card="staticCards.children"
        />

        <!-- 兄弟數量 -->
        <AppTripleDetailStaticCard v-else :static-card="staticCards.siblings" />

        <!-- 關聯數量 -->

        <AppTripleDetailStaticCard
            v-if="type === 'scopes'"
            :static-card="staticCards.relationships"
        />

        <!-- 記錄數量 -->
        <AppTripleDetailStaticCard :static-card="staticCards.records" />
    </div>
</template>
<script setup>
import AppTripleDetailStaticCard from "./AppTripleDetailStaticCard.vue";
const { detail, type } = defineProps({
    detail: Object,
    type: String,
});

const staticCards = {
    children: {
        title: "子類數量",
        counter: detail.children?.length || 0,
    },
    siblings: {
        title: "兄弟類數量",
        counter: detail.siblings?.length || 0,
    },
    relationships: {
        title: "關聯數量",
        counter:
            (detail.object_of?.length || 0) + (detail.subject_of?.length || 0),
    },
    records: {
        title: "記錄數量",
        counter: 0,
    },
};
</script>
