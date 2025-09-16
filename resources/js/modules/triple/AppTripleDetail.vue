<template>
    <div class="h-full">
        <div v-if="detail" class="flex flex-col h-full">
            <!-- Header -->
            <AppTripleDetailHeader :target="target" :detail="detail" />

            <div class="flex flex-1 min-h-0 overflow-hidden gap-3 w-full">
                <div class="flex-1 flex flex-col min-h-0 overflow-hidden">
                    <AppTripleDetailContentTable />
                    <!-- Scrollable content -->

                    <div
                        class="flex-1 overflow-auto min-h-0 scroll-blend"
                        ref="scrollContainer"
                        @scroll="onScroll"
                    >
                        <div class="flex flex-col gap-4 py-2 w-full">
                            <AppTripleDetailContainer content-title="Metadata">
                                <template #icon>
                                    <CodeBracketSquareIcon
                                        class="inline-block w-[1em] h-[1em]"
                                    />
                                </template>
                                <AppTripleDetailMetadata
                                    :detail="detail"
                                    :type="target.title"
                                    :preload="preload"
                                />
                            </AppTripleDetailContainer>

                            <AppTripleDetailContainer
                                content-title="Relationship"
                                v-if="target.title == 'scopes'"
                            >
                                <template #icon>
                                    <LinkIcon
                                        class="inline-block w-[1em] h-[1em]"
                                    />
                                </template>
                                <AppTripleDetailRelationship
                                    :detail="detail"
                                    :preload="preload"
                                    :on-scroll-check="checkScrollable"
                                />
                            </AppTripleDetailContainer>

                            <AppTripleDetailContainer content-title="Statistic">
                                <template #icon>
                                    <ChartBarSquareIcon
                                        class="inline-block w-[1em] h-[1em]"
                                    />
                                </template>
                                <AppTripleDetailStatic
                                    :detail="detail"
                                    :type="target.title"
                                />
                            </AppTripleDetailContainer>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col">
                    <AppTripleDetailVersion :detail="detail" />
                </div>
            </div>
        </div>
        <div v-else class="flex items-center justify-center h-full">
            <p class="text-gray-500">請選擇一個項目以查看詳細資訊。</p>
        </div>

        <!-- 這裡改成傳 ref 了 -->
        <!-- <AppTripleFloatingWidget :target="target" /> -->
    </div>
</template>
<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { storeToRefs } from "pinia";
import { useTripleSelectionStore } from "@/stores/useTripleSelectionStore";
import { fetchAPI } from "../../utils/useFetchAPI.js";
import { useDataStore } from "@/stores/useDataStore";
import AppTripleDetailContainer from "./AppTripleDetailContainer.vue";
import AppTripleDetailMetadata from "./AppTripleDetailMetadata.vue";
import AppTripleDetailRelationship from "./AppTripleDetailRelationship.vue";
import AppTripleDetailStatic from "./AppTripleDetailStatic.vue";
import AppTripleDetailHeader from "./AppTripleDetailHeader.vue";
import AppTripleDetailVersion from "./AppTripleDetailVersion.vue";
import AppTripleFloatingWidget from "./AppTripleFloatingWidget.vue";
import AppTripleDetailNav from "./AppTripleDetailNav.vue";
import AppTripleDetailContentTable from "./AppTripleDetailContentTable.vue";

import {
    CodeBracketSquareIcon,
    LinkIcon,
    ChartBarSquareIcon,
} from "@heroicons/vue/16/solid";

const preload = useDataStore();

const selection = useTripleSelectionStore();
const { selected: target } = storeToRefs(selection);
const detail = ref(null);

const scrollContainer = ref(null);
const showTopShadow = ref(false);
const showBottomShadow = ref(false);
const showDetailNav = ref(false);

const onScroll = (e) => {
    checkScrollable();
};

onMounted(() => {
    nextTick(() => {
        checkScrollable();
    });
});

const checkScrollable = () => {
    const el = scrollContainer.value;
    if (!el) return;
    const { scrollTop, scrollHeight, clientHeight } = el;
    showTopShadow.value = scrollTop > 0;
    showBottomShadow.value = scrollTop + clientHeight < scrollHeight - 1;
    showDetailNav.value = scrollHeight > clientHeight;
};

watch(
    detail,
    () => {
        nextTick(() => {
            checkScrollable();
        });
    },
    { deep: true },
);

watch(
    () => target.value,
    async (newSelected) => {
        if (!newSelected || !newSelected.title || !newSelected.item?.id) {
            detail.value = null;
            return;
        }
        try {
            const response = await fetchAPI(
                `/api/${newSelected.title}/${newSelected.item.id}`,
            );
            detail.value = response;
        } catch (error) {
            console.error("Failed to fetch detail:", error);
            detail.value = null;
        }
    },
    { immediate: true, deep: true },
);

const selectedNav = ref("Metadata");
const sections = ["Metadata", "Relationship", "Statistic"];

function currentSelectedDetailNav(section) {
    selectedNav.value = section;
}
</script>
