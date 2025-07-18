<template>
    <div class="flex flex-col h-full divide-y divide-stone-200">
        <!-- 類號/子類號區塊 -->
        <div class="flex-[2] py-1">
            <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
                <div>
                    <dt class="font-medium text-gray-700 pb-1">類號</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ classNumber }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700 pb-1">子類號</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ callNumber }}
                    </dd>
                </div>
            </dl>
        </div>

        <!-- 關係區塊（僅 relation 類型顯示） -->
        <div v-if="type === 'relations'" class="flex-[2] py-1">
            <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
                <div>
                    <dt class="font-medium text-gray-700 pb-1">主詞</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ subjectCURIE }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700 pb-1">受詞</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ objectCURIE }}
                    </dd>
                </div>
            </dl>
        </div>

        <!-- 範圍說明/註釋區塊 -->
        <div class="flex-[2] py-1 overflow-auto">
            <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
                <div v-if="comment">
                    <dt class="font-medium text-gray-700 pb-1">範圍說明</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">{{ comment }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700 pb-1">註釋</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">{{ note }}</dd>
                </div>
            </dl>
        </div>
    </div>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
    preload: {
        type: Object,
        required: true,
    },
    type: {
        type: String,
        required: true,
    },
});

const classNumber = computed(() => props.detail?.class_number ?? "-");
const callNumber = computed(() => props.detail?.call_number ?? "-");
const comment = computed(() => props.detail?.comment ?? "");
const note = computed(() => props.detail?.note ?? "");
const subjectCURIE = computed(
    () => props.preload?.scopesDict?.[props.detail?.subject]?.CURIE ?? "-",
);
const objectCURIE = computed(
    () => props.preload?.scopesDict?.[props.detail?.object]?.CURIE ?? "-",
);
</script>
