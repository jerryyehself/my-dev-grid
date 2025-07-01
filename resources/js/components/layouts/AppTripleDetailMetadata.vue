<template>
    <div class="flex flex-col h-full divide-y divide-stone-200">
        <div class="flex-[2] py-1">
            <!-- 第一區 -->
            <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
                <div>
                    <dt class="font-medium text-gray-700">類號</dt>
                    <dd>{{ detail.class_number }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">子類號</dt>
                    <dd>{{ detail.call_number }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex-[2] py-1 overflow-auto">
            <!-- 第二區 -->
            <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
                <div>
                    <dt class="font-medium text-gray-700">範圍說明</dt>
                    <dd>{{ detail.comment }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">註釋</dt>
                    <dd>{{ detail.note }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex-[1] py-1">
            <!-- 第三區 -->
            <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
                <div>
                    <dt class="font-medium text-gray-900">Created at</dt>
                    <dd>{{ detail.created_at }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-900">Updated at</dt>
                    <dd>{{ detail.updated_at }}</dd>
                </div>
            </dl>
        </div>
    </div>
</template>
<script setup>
defineProps({
    detail: Object,
});
</script>
