<template>
    <div
        class="fixed p-4 right-0 bottom-10 z-50 flex flex-col w-full max-w-[160px] rounded-l-md shadow-md shadow-gray-500 bg-white/80 backdrop-blur-sm border border-stone-300"
    >
        <h4 class="pb-2 font-bold text-center text-shadow-lg">Action</h4>
        <div class="flex flex-col gap-2">
            <AppWidgetButton
                v-for="(button, index) in buttonConfigs"
                :key="index"
                :button="button"
            />
        </div>
    </div>
</template>

<script setup>
import { h, computed } from "vue";
import AppWidgetButton from "../widgets/AppWidgetButton.vue";
import { useTripleAction } from "@/stores/useTripleAction";
import {
    DocumentArrowUpIcon,
    TrashIcon,
    PlusIcon,
} from "@heroicons/vue/24/outline";

const { detail } = defineProps({
    detail: Object,
});

const selectAction = useTripleAction();
const action = computed(() => selectAction.action);
const buttonConfigs = [
    {
        label: "Modify",
        color: "bg-stone-500",
        icon: h(DocumentArrowUpIcon, { class: "w-4 h-4" }),
        ability: computed(() => {
            return !!detail;
        }),

        action: () => setAction("update"),
    },
    {
        label: "Delete",
        color: "bg-red-400",
        icon: h(TrashIcon, { class: "w-4 h-4" }),
        ability: computed(() => {
            return !!detail;
        }),

        action: () => setAction("delete"),
    },
];
</script>
