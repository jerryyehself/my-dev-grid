<template>
    <nav
        v-if="Array.isArray(filteredScopes) && Array.isArray(filteredRelations)"
        class="flex flex-col h-full min-h-0 overflow-hidden"
    >
        <!-- 搜尋欄 -->
        <div class="p-3 m-5 bg-stone-200 h-16">
            <label
                class="relative h-full w-full flex items-center group cursor-pointer"
            >
                <div
                    class="absolute left-2 top-1/2 -translate-y-1/2 flex items-center justify-center z-20 pointer-events-none"
                >
                    <svg
                        class="w-5 h-5 text-stone-500"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <path
                            d="M21 21l-4.35-4.35"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        ></path>
                        <circle
                            cx="11"
                            cy="11"
                            r="6"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        ></circle>
                    </svg>
                </div>

                <!-- 輸入框 -->
                <div class="relative w-64">
                    <input
                        v-model="searchKeyword"
                        id="search"
                        type="text"
                        placeholder="Search..."
                        class="peer w-full bg-transparent border-none outline-none text-stone-900 pl-8 py-1"
                    />
                    <!-- 聚焦時跑底線 -->
                    <span
                        class="absolute bottom-0 left-0 h-[2px] w-0 bg-stone-400 transition-all duration-300 peer-focus:w-full z-10"
                    ></span>
                </div>
            </label>
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
