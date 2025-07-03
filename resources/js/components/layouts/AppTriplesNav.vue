<template>
    <nav
        v-if="scopes.items?.data && relations.items?.data"
        class="flex flex-col h-full min-h-0 overflow-hidden"
    >
        <!-- 搜尋欄 -->
        <div class="p-2">
            <input
                v-model="searchText"
                type="text"
                class="w-full rounded border border-stone-400 px-2 py-1 text-sm text-stone-800 dark:text-white dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-stone-600"
                placeholder="Search..."
            />
        </div>

        <!-- scopes 區塊 -->
        <hr class="border-t border-stone-300 dark:border-stone-600" />
        <AppTriplesNavList
            title="scopes"
            :items="filteredScopes"
            :type="scopes.type"
            :is-open="openList === 'scopes'"
            @toggle="handleToggle('scopes')"
            :expand="openList === 'scopes'"
        />

        <!-- relations 區塊 -->
        <hr class="border-t border-stone-300 dark:border-stone-600" />
        <AppTriplesNavList
            title="relations"
            :items="filteredRelations"
            :type="relations.type"
            :is-open="openList === 'relations'"
            @toggle="handleToggle('relations')"
            :expand="openList === 'relations'"
        />
    </nav>
</template>

<script setup>
import { ref, computed } from "vue";
import { useData } from "@/stores/useData";
import AppTriplesNavList from "./AppTriplesNavList.vue";

const preload = useData();
const searchText = ref("");
const openList = ref("scopes");

// 取得 scopes / relations 資料
const scopes = computed(() => ({
    title: "scopes",
    type: "scopes",
    items: preload.scopesData,
}));

const relations = computed(() => ({
    title: "relations",
    type: "relations",
    items: preload.relationsData,
}));

// 點擊展開/收合邏輯
function handleToggle(listName) {
    openList.value = openList.value === listName ? "" : listName;
}

// 根據搜尋字過濾
const filteredScopes = computed(() =>
    scopes.value.items.data.filter((item) =>
        item.name?.toLowerCase().includes(searchText.value.toLowerCase()),
    ),
);

const filteredRelations = computed(() =>
    relations.value.items.data.filter((item) =>
        item.name?.toLowerCase().includes(searchText.value.toLowerCase()),
    ),
);
</script>
