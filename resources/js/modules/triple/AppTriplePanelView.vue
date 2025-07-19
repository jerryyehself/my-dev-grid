<template>
    <Transition :name="panelChange" mode="out-in">
        <template v-if="panelSelected === 'new'">
            <AppTripleNew class="h-full w-full flex flex-col" />
        </template>
        <template v-else>
            <div
                class="col-span-5 grid grid-cols-5 min-h-0 max-h-full overflow-hidden h-full"
            >
                <section class="col-span-1 flex flex-col min-h-0 bg-stone-300">
                    <AppTripleNavList />
                </section>
                <section
                    class="col-span-4 flex flex-col min-h-0 max-h-full overflow-hidden h-full"
                >
                    <AppTripleDetail class="flex-1 overflow-auto" />
                </section>
            </div>
        </template>
    </Transition>
</template>

<script setup>
import { ref, watch, computed } from "vue";
import { storeToRefs } from "pinia";
import AppTripleNew from "./AppTripleNew.vue";
import AppTripleNavList from "./AppTripleNav.vue";
import AppTripleDetail from "./AppTripleDetail.vue";
import { useTriplePanelSelectionStore } from "@/stores/useTriplePanelSelectionStore";

// 取 store 狀態
const store = useTriplePanelSelectionStore();
const { panelSelected } = storeToRefs(store);

// 記錄前一個 panel
const prevPanel = ref(panelSelected.value);

// 每次 panel 改變時更新 prevPanel
watch(panelSelected, (newVal, oldVal) => {
    prevPanel.value = oldVal; // 先記錄舊值
});

// 動畫方向：從其他 -> new 就 slide-left，反之 slide-right
const panelChange = computed(() =>
    prevPanel.value !== "new" && panelSelected.value === "new"
        ? "slide-left"
        : "slide-right",
);
</script>

<style scoped>
.slide-left-enter-active,
.slide-left-leave-active,
.slide-right-enter-active,
.slide-right-leave-active {
    transition:
        transform 0.3s ease,
        opacity 0.3s ease;
}

.slide-left-enter-from {
    transform: translateX(-100%);
    opacity: 0;
}
.slide-left-leave-to {
    transform: translateX(100%);
    opacity: 0;
}
.slide-right-enter-from {
    transform: translateX(100%);
    opacity: 0;
}
.slide-right-leave-to {
    transform: translateX(-100%);
    opacity: 0;
}
</style>
