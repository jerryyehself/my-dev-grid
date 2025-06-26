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
        class="flex flex-col space-y-2 max-h-full min-h-0 overflow-hidden"
    >
        <AppTriplesNavList
            title="scopes"
            :items="scopes.items.data"
            :type="scopes.type"
            :is-open="openList === 'scopes'"
            @toggle="handleToggle('scopes')"
            :expand="openList === 'scopes'"
        />
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
