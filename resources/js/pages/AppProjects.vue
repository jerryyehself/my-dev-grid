<template>
    <AppProjectSearch />
    <div
        class="col-span-5 flex flex-col min-h-0 h-full"
        v-if="dataStore.projectsData"
    >
        <div
            class="flex-1 min-h-0 overflow-auto px-20 py-5 grid lg:grid-cols-4 sm:grid-cols-3 gap-5 scroll-blend"
        >
            <!-- {{ dataStore.projectsData[0].gitService[0] }} -->
            <div
                class="row-span-1"
                v-for="value in dataStore.projectsData[0].gitService"
                :key="value.id"
            >
                <div
                    class="max-w-sm bg-white border border-stone-200 shadow-md hover:shadow-lg transition-shadow rounded-none"
                >
                    <!-- 專案封面（可用圖片或程式碼截圖） -->
                    <div
                        class="bg-stone-100 h-40 flex items-center justify-center text-stone-400 text-sm"
                    >
                        Project Preview
                    </div>

                    <!-- 內容 -->
                    <div class="p-4 flex flex-col gap-2">
                        <h3 class="text-xl font-bold text-stone-800">
                            {{ value.name }}
                        </h3>
                        <span class="text-xs text-stone-800">2025</span>
                        <p class="text-sm text-stone-600 leading-relaxed">
                            一個用 Vue + Laravel 打造的 API
                            Dashboard，支援動態過濾與即時更新。
                            {{ value }}
                        </p>

                        <!-- 標籤 -->
                        <div class="flex flex-wrap gap-2 mt-2">
                            <span
                                v-for="topic in value.topics"
                                key="topic"
                                class="px-2 py-0.5 bg-stone-100 text-stone-600 text-xs border border-stone-200"
                                >{{ topic }}</span
                            >
                        </div>

                        <!-- 底部操作 -->
                        <div
                            class="mt-4 flex justify-between items-center border-t border-stone-100 pt-3"
                        >
                            <a
                                :href="value.html_url"
                                target="_blank"
                                class="text-stone-700 text-sm font-medium hover:underline"
                                >查看程式碼 →</a
                            >
                        </div>
                    </div>
                </div>
                <!-- <div class="col-span-1">
                {{ value.name }}
            </div>
            <div class="col-span-1"></div>
            <div class="col-span-1"></div>
            <div class="col-span-1"></div> -->
            </div>
        </div>
    </div>
</template>
<script setup>
import { onMounted } from "vue";
import { useProjectsStore } from "@/stores/useProjectsStore";
import AppProjectSearch from "../modules/projects/AppProjectSearch.vue";

const dataStore = useProjectsStore();

onMounted(async () => {
    if (!dataStore.isLoaded) await dataStore.fetchProjects();
    console.log(dataStore.projectsData[0].gitService[0].name);
});
</script>
