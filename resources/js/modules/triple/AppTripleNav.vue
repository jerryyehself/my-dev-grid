<template>
    <nav
        v-if="Array.isArray(filteredScopes) && Array.isArray(filteredRelations)"
        class="flex flex-col h-full min-h-0 overflow-hidden"
    >
        <!-- 搜尋欄 -->
        <div class="p-2">
            <input
                v-model="searchKeyword"
                type="text"
                class="w-full rounded border border-stone-400 px-2 py-1 text-sm text-stone-800 dark:text-white dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-stone-600"
                placeholder="Search..."
            />
        </div>

        <template
            v-for="section in [
                {
                    key: 'scopes',
                    title: 'scopes',
                    items: filteredScopes,
                    type: scopes.type,
                },
                {
                    key: 'relations',
                    title: 'relations',
                    items: filteredRelations,
                    type: relations.type,
                },
            ]"
            :key="section.key"
        >
            <hr class="border-t border-stone-300 dark:border-stone-600" />
            <AppTripleNavList
                :title="section.title"
                :items="section.items"
                :type="section.type"
                :is-open="expandedSection === section.key"
                @toggle="toggleNavSection(section.key)"
                :expand="expandedSection === section.key"
            />
        </template>
    </nav>
</template>

<script setup>
import { ref, computed } from "vue";
import { useDataStore } from "@/stores/useDataStore";
import AppTripleNavList from "./AppTripleNavList.vue";

const dataStore = useDataStore();
const searchKeyword = ref("");
const expandedSection = ref("scopes");

const scopes = computed(() => ({
    title: "scopes",
    type: "scopes",
    items: dataStore.scopesData,
}));

const relations = computed(() => ({
    title: "relations",
    type: "relations",
    items: dataStore.relationsData,
}));

function filterByKeyword(list, keyword) {
    if (!Array.isArray(list)) return [];
    const kw = keyword.trim().toLowerCase();
    if (!kw) return list;
    return list.filter((item) => item.name?.toLowerCase().includes(kw));
}

const filteredScopes = computed(() =>
    filterByKeyword(scopes.value.items?.data, searchKeyword.value),
);

const filteredRelations = computed(() =>
    filterByKeyword(relations.value.items?.data, searchKeyword.value),
);

function toggleNavSection(sectionKey) {
    expandedSection.value =
        expandedSection.value === sectionKey ? "" : sectionKey;
}
</script>
