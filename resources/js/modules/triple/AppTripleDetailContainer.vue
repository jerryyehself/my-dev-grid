<template>
    <div class="flex flex-col h-full p-2 border-gray-100 border shadow-md">
        <h3
            class="text-xl font-semibold text-gray-800 flex items-center gap-1 mb-2 shrink-0"
            :id="sectionId"
        >
            <slot name="icon"></slot>
            {{ props.contentTitle }}
        </h3>

        <div class="flex-1 overflow-auto min-h-0">
            <slot>N/A</slot>
        </div>
    </div>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
    contentTitle: {
        type: String,
        required: true,
    },
});

const sectionId = computed(() => props.contentTitle);
</script>
