<template>
    <div class="flex">
        <div
            class="bg-stone-700 text-white px-1.5 font-bold text-sm"
            :class="itemMeta.area ? 'rounded-s-sm' : 'rounded-sm'"
        >
            {{ itemMeta.type }}
        </div>
        <div
            class="text-white bg-stone-500 rounded-e-sm px-1.5 font-bold text-sm"
            :class="{ hidden: !itemMeta.area }"
        >
            {{ itemMeta.area }}
        </div>
    </div>
</template>
<script setup>
const { itemMeta } = defineProps({
    itemMeta: Object,
});
</script>
