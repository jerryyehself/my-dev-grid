<script setup>
import { ref, computed, watch } from "vue";
import { useData } from "@/stores/useData";
import AppTriplesNavList from "./AppTriplesNavList.vue";

const preload = useData();
const scopes = computed(() => ({
    title: "scopes",
    items: preload.scopesData,
}));

const relations = computed(() => ({
    title: "relations",
    items: preload.relationsData,
}));

const openList = ref("scopes");

function handleToggle(listName) {
    openList.value = openList.value === listName ? "" : listName;
}
</script>
<template>
    <nav
        v-if="scopes.items?.data && relations.items?.data"
        class="flex flex-col h-full min-h-0 overflow-hidden"
    >
        <div class="p-1">
            <input
                type="text"
                class="border border-stone-500 rounded px-1 py-0.5 w-full focus:outline-none focus:ring-2 focus:ring-stone-700"
                placeholder="search..."
            />
            <!-- <input
                    type="text"
                    v-model="search"
                    class="border border-stone-500 rounded px-1 py-0.5 w-full mb-1 focus:outline-none focus:ring-2 focus:ring-stone-700"
                    placeholder="search..."
                /> -->
        </div>
        <hr class="border-t border-white" />
        <AppTriplesNavList
            title="scopes"
            :items="scopes.items.data"
            :type="scopes.type"
            :is-open="openList === 'scopes'"
            @toggle="handleToggle('scopes')"
            :expand="openList === 'scopes'"
        />
        <hr class="border-t border-white" />
        <AppTriplesNavList
            title="relations"
            :items="relations.items.data"
            :type="relations.type"
            :is-open="openList === 'relations'"
            @toggle="handleToggle('relations')"
            :expand="openList === 'relations'"
        />
    </nav>
</template>
