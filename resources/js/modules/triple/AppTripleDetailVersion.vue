<template>
    <button
        class="flex flex-col gap-2 text-gray-600 border-2 border-stone-400 rounded p-1 cursot-pointer text-left cursor-pointer"
    >
        <span class="font-bold">ver.1</span>
        <dl class="text-sm">
            <div>
                <dt class="font-medium text-gray-900">Created at</dt>
                <dd>{{ createdAt }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-900">Updated at</dt>
                <dd>{{ updatedAt }}</dd>
            </div>
        </dl>
    </button>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
});

const createdAt = computed(() => props.detail?.created_at ?? "-");
const updatedAt = computed(() => props.detail?.updated_at ?? "-");
</script>
