<template>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
        <!-- 子類數量 -->
        <div
            v-if="!detail.parent"
            class="bg-white dark:bg-stone-800 shadow-sm rounded-lg p-4"
        >
            <dt class="text-sm text-gray-500 dark:text-gray-300">子類數量</dt>
            <dd
                class="mt-1 text-xl font-semibold text-stone-700 dark:text-white"
            >
                {{ detail.children?.length || 0 }}
            </dd>
        </div>

        <!-- 兄弟數量 -->
        <div v-else class="bg-white dark:bg-stone-800 shadow-sm rounded-lg p-4">
            <dt class="text-sm text-gray-500 dark:text-gray-300">兄弟數量</dt>
            <dd
                class="mt-1 text-xl font-semibold text-stone-700 dark:text-white"
            >
                {{ detail.siblings?.length || 0 }}
            </dd>
        </div>

        <!-- 關聯數量 -->
        <div
            v-if="type === 'scopes'"
            class="bg-white dark:bg-stone-800 shadow-sm rounded-lg p-4"
        >
            <dt class="text-sm text-gray-500 dark:text-gray-300">關聯數量</dt>
            <dd
                class="mt-1 text-xl font-semibold text-stone-700 dark:text-white"
            >
                {{
                    (detail.object_of?.length || 0) +
                    (detail.subject_of?.length || 0)
                }}
            </dd>
        </div>

        <!-- 記錄數量 -->
        <div class="bg-white dark:bg-stone-800 shadow-sm rounded-lg p-4">
            <dt class="text-sm text-gray-500 dark:text-gray-300">記錄數量</dt>
            <dd
                class="mt-1 text-xl font-semibold text-stone-700 dark:text-white"
            >
                <!-- 根據實際數據補上 -->
                0
            </dd>
        </div>
    </div>
</template>
<script setup>
defineProps({
    detail: Object,
    type: String,
});
</script>
