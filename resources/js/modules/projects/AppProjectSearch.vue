<template>
    <div
        class="flex w-full gap-6 px-20 align-middle justify-center p-5 shadow-md"
    >
        <div class="">
            <!-- <input
                v-model="searchKeyword"
                id="search"
                type="text"
                placeholder="Search..."
                class="peer w-full outline-none text-stone-900 pl-4 py-1 bg-white h-12 border border-stone-300"
            /> -->
            <!-- 聚焦時跑底線 -->
        </div>
        <div class="w-2/3">
            <div class="mt-2">
                <div
                    class="flex items-center outline-1 -outline-offset-1 has-[input:focus-within]:outline-2 has-[input:focus-within]:-outline-offset-2 outline-stone-200"
                >
                    <input
                        v-model="searchKeyword"
                        id="search"
                        type="text"
                        placeholder="Search..."
                        class="peer w-full outline-none text-stone-900 pl-4 py-1 bg-white h-12 border border-stone-200"
                    />
                    <div
                        class="grid shrink-0 grid-cols-1 focus-within:relative"
                    >
                        <select
                            id="currency"
                            name="currency"
                            aria-label="Currency"
                            class="col-start-1 row-start-1 py-1.5 pl-3 pr-8 text-base text-stone-400 focus:outline-0"
                        >
                            <option value="">All</option>
                            <option value="title">Title</option>
                            <option value="language">Language</option>
                        </select>
                        <ChevronDownIcon
                            class="pointer-events-none row-start-1 size-5 self-center justify-self-end text-gray-400 sm:size-4 -mr-1"
                            aria-hidden="true"
                        />
                    </div>
                </div>
            </div>
        </div>
        <div class="flex align-middle">
            <button
                class="bg-stone-300 text-white px-6 py-2 hover:bg-stone-400 transition-colors mt-2 cursor-pointer"
            >
                Search
            </button>
        </div>
        <!-- <button
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
        </button> -->
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
