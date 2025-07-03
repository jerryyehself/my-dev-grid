<template>
    <div v-if="detail" class="flex flex-col">
        <AppTripleDetailHeader :target="target" :detail="detail" />
        <div class="grid grid-cols-12 h-full overflow-hidden">
            <div class="col-span-10 flex flex-col h-full overflow-hidden">
                <div
                    ref="scrollContainer"
                    @scroll="onScroll"
                    :class="[
                        'flex-1 flex flex-col overflow-y-auto min-h-0 scroll-blend transition-shadow duration-200 scroll-smooth',
                        {
                            'shadow-[inset_0_6px_6px_-6px_rgba(0,0,0,0.2)]':
                                showTopShadow,
                            'shadow-[inset_0_-6px_6px_-6px_rgba(0,0,0,0.2)]':
                                showBottomShadow,
                        },
                    ]"
                >
                    <div class="flex flex-col gap-4 py-2 px-4">
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
                <AppTripleDetailFooter :detail="detail" />
            </div>

            <!-- 右側空白 -->
            <div class="col-span-2 py-5 flex flex-col px-6 gap-3">
                <div
                    v-if="showDetailNav"
                    class="flex flex-col flex-1 justify-between"
                >
                    <ul>
                        <li
                            v-for="section in sections"
                            :key="section"
                            class="hover:text-shadow-sm"
                        >
                            <a
                                :href="'#' + section"
                                @click="currentSelectedDetailNav(section)"
                                :class="[
                                    'text-gray-400',
                                    {
                                        'text-shadow-sm':
                                            selectedNav === section,
                                    },
                                ]"
                            >
                                # {{ section }}
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="flex flex-col gap-1 w-full max-w-[160px]">
                    <AppWidgetButton
                        v-for="(button, index) in buttonConfigs"
                        :button="button"
                        :key="index"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch, onMounted, nextTick, h } from "vue";
import { useSelectionStore } from "@/stores/useSelectionStore";
import { fetchAPI } from "../../fetchAPI.js";
import { useData } from "@/stores/useData";
import AppTripleDetailContainer from "./AppTripleDetailContainer.vue";
import AppTripleDetailMetadata from "./AppTripleDetailMetadata.vue";
import AppTripleDetailRelationship from "./AppTripleDetailRelationship.vue";
import AppTripleDetailStatic from "./AppTripleDetailStatic.vue";
import AppTripleDetailHeader from "./AppTripleDetailHeader.vue";
import AppTripleDetailFooter from "./AppTripleDetailFooter.vue";
import AppWidgetButton from "../widgets/AppWidgetButton.vue";

import {
    CodeBracketSquareIcon,
    LinkIcon,
    ChartBarSquareIcon,
} from "@heroicons/vue/16/solid";
import {
    DocumentArrowUpIcon,
    TrashIcon,
    PlusIcon,
} from "@heroicons/vue/24/outline";

const preload = useData();

const selection = useSelectionStore();

const target = computed(() => selection.selected);
const detail = ref(null);

const scrollContainer = ref(null);
const showTopShadow = ref(false);
const showBottomShadow = ref(false);

const showDetailNav = ref(false);
const sections = ["Metadata", "Relationship", "Statistic"];
const selectedNav = ref("Metadata");

function currentSelectedDetailNav(section) {
    selectedNav.value = section;
}

const onScroll = (e) => {
    checkScrollable();
};

onMounted(() => {
    nextTick(() => {
        checkScrollable();
    });
});

const buttonConfigs = [
    {
        label: "New",
        color: "bg-emerald-600",
        icon: h(DocumentArrowUpIcon, { class: "w-4 h-4" }), // 👈 已指定大小
        // action: () => doSomething(),
    },
    {
        label: "Modify",
        color: "bg-stone-500",
        icon: h(DocumentArrowUpIcon, { class: "w-4 h-4" }),
        // action: () => modify(),
    },
    {
        label: "Delete",
        color: "bg-red-400",
        icon: h(TrashIcon, { class: "w-4 h-4" }),
        // action: () => deleteItem(),
    },
];

const checkScrollable = () => {
    const el = scrollContainer.value;

    if (!el) return;

    const { scrollTop, scrollHeight, clientHeight } = el;

    showTopShadow.value = scrollTop > 0;
    showBottomShadow.value = scrollTop + clientHeight < scrollHeight - 1;
    showDetailNav.value = scrollHeight > clientHeight;
};

watch(
    () => detail,
    () => {
        nextTick(() => {
            checkScrollable();
        });
    },
    { deep: true },
);

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
