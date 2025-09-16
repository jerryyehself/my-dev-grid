<template>
    <div class="flex w-full bg-stone-200 gap-6 px-20">
        <button
            v-for="(panel, key) in panelNavList"
            :key="key"
            @click="panelStatus.setPanel(key)"
            :class="[
                'font-bold text-center p-3 cursor-pointer flex items-center justify-center ',
                panelStatus.panelSelected === key
                    ? 'shadow-[inset_0_-5px_0_0_rgb(168,162,158)]'
                    : '',
            ]"
        >
            <span>{{ panel.label }}</span>
        </button>
    </div>
</template>

<script setup>
import { h } from "vue";
import {
    DocumentPlusIcon,
    AdjustmentsVerticalIcon,
} from "@heroicons/vue/16/solid";
import { useTriplePanelSelectionStore } from "@/stores/useTriplePanelSelectionStore";

const panelStatus = useTriplePanelSelectionStore();

const panelNavList = {
    admin: {
        label: "Admin",
        direct: "admin",
        icon: h(AdjustmentsVerticalIcon, { class: "w-4 h-4" }),
    },
    new: {
        label: "New",
        direct: "addNew",
        icon: h(DocumentPlusIcon, { class: "w-4 h-4" }),
    },
};
</script>
