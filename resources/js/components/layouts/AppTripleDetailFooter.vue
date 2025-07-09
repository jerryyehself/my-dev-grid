<template>
    <footer class="py-5 px-4">
        <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
            <div>
                <dt class="font-medium text-gray-900">Created at</dt>
                <dd>{{ createdAt }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-900">Updated at</dt>
                <dd>{{ updatedAt }}</dd>
            </div>
        </dl>
    </footer>
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
