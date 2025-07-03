<template>
    <div class="flex flex-col h-full divide-y divide-stone-200">
        <div class="flex-[2] py-1">
            <!-- 第一區 -->
            <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
                <div>
                    <dt class="font-medium text-gray-700 pb-1">類號</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ detail.class_number }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700 pb-1">子類號</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ detail.call_number }}
                    </dd>
                </div>
            </dl>
        </div>
        <div v-if="type == 'relations'" class="flex-[2] py-1">
            <!-- 關係 -->
            <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
                <div>
                    <dt class="font-medium text-gray-700 pb-1">主詞</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ preload.scopesDict[detail.subject]?.CURIE }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700 pb-1">受詞</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ preload.scopesDict[detail.object]?.CURIE }}
                    </dd>
                </div>
            </dl>
        </div>

        <div class="flex-[2] py-1 overflow-auto">
            <!-- 第二區 -->
            <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
                <div v-if="detail.comment">
                    <dt class="font-medium text-gray-700 pb-1">範圍說明</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ detail.comment }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700 pb-1">註釋</dt>
                    <dd class="rounded-sm bg-stone-200 pl-1">
                        {{ detail.note }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</template>
<script setup>
defineProps({
    detail: Object,
    preload: Object,
    type: String,
});
</script>
