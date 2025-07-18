<template>
    <div class="flex">
        <div
            class="bg-stone-700 text-white px-1.5 font-bold text-sm"
            :class="props.itemMeta.area ? 'rounded-s-sm' : 'rounded-sm'"
        >
            {{ props.itemMeta.type }}
        </div>
        <div
            class="text-white bg-stone-500 rounded-e-sm px-1.5 font-bold text-sm"
            :class="{ hidden: !props.itemMeta.area }"
        >
            {{ props.itemMeta.area }}
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    itemMeta: {
        type: Object,
        required: true,
    },
});
</script>
