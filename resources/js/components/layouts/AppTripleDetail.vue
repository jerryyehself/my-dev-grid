<template>
    <div v-if="detail" class="grid grid-cols-12 h-full">
        <div class="col-span-2"></div>

        <div class="col-span-8 flex flex-col h-full overflow-hidden">
            <div class="shrink-0 py-2.5 flex items-center space-x-4 shadow">
                <h2 class="text-2xl font-bold text-stone-800">
                    {{ detail.name }}
                </h2>
                <AppTripleItemMetaTag :itemMeta="itemMeta" />
            </div>

            <div
                class="flex-1 flex flex-col overflow-y-auto min-h-0 scroll-blend"
            >
                <div class="flex flex-col gap-4 px-0 pt-2 pb-6 pr-2">
                    <AppTripleDetailContainer content-title="Metadata">
                        <template #icon>
                            <CodeBracketSquareIcon
                                class="inline-block w-[1em] h-[1em]"
                            />
                        </template>
                        <AppTripleDetailMetadata :detail="detail" />
                    </AppTripleDetailContainer>

                    <AppTripleDetailContainer content-title="Relationship">
                        <template #icon>
                            <LinkIcon class="inline-block w-[1em] h-[1em]" />
                        </template>
                        <AppTripleDetailRelationship
                            :detail="detail"
                            :preload="preload"
                        />
                    </AppTripleDetailContainer>

                    <AppTripleDetailContainer content-title="Statistic">
                        <template #icon>
                            <ChartBarSquareIcon
                                class="inline-block w-[1em] h-[1em]"
                            />
                        </template>
                        <AppTripleDetailStatic :detail="detail" />
                    </AppTripleDetailContainer>
                </div>
            </div>
        </div>

        <!-- 右側空白 -->
        <div class="col-span-2"></div>
    </div>
</template>

<script setup>
import AppTripleItemMetaTag from "./AppTripleItemMetaTag.vue";
import { computed, ref, watch } from "vue";
import { useSelectionStore } from "@/stores/useSelectionStore";
import { fetchAPI } from "../../fetchAPI.js";
import { useData } from "@/stores/useData";
import AppTripleDetailContainer from "./AppTripleDetailContainer.vue";
import AppTripleDetailMetadata from "./AppTripleDetailMetadata.vue";
import AppTripleDetailRelationship from "./AppTripleDetailRelationship.vue";
import AppTripleDetailStatic from "./AppTripleDetailStatic.vue";
import {
    CodeBracketSquareIcon,
    LinkIcon,
    ChartBarSquareIcon,
} from "@heroicons/vue/16/solid";

const preload = useData();

const selection = useSelectionStore();

const target = computed(() => selection.selected);
const detail = ref(null);
const itemMeta = computed(() => ({
    type: target.value.title,
    area: target.value.item?.parent?.name,
}));

watch(
    () => selection.selected,
    async (newSelected) => {
        if (!newSelected || !target.value?.title || !target.value?.item?.id)
            return;

        try {
            const response = await fetchAPI(
                `/api/${target.value.title}/${target.value.item.id}`,
            );
            detail.value = response;
        } catch (error) {
            console.error("Failed to fetch detail:", error);
            detail.value = null;
        }
    },
    { immediate: true },
);
</script>
