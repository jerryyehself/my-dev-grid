<template>
    <button
        v-for="(panel, key) in panelNavList"
        :key="key"
        @click="model = key"
        :class="[
            'shadow-inner font-bold text-center p-3 cursor-pointer flex items-center justify-center',
            model === key
                ? 'bg-stone-600 text-white'
                : 'bg-stone-300 text-gray-700',
        ]"
    >
        <component :is="panel.icon" />
        <span>{{ panel.label }}</span>
    </button>
</template>

<script setup>
import { h } from "vue";
import {
    DocumentPlusIcon,
    AdjustmentsVerticalIcon,
} from "@heroicons/vue/16/solid";

const model = defineModel();
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
