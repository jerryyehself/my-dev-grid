<template>
    <div class="flex">
        <div class="bg-stone-700 text-stone-300 px-1.5 font-bold text-sm" :class="area ? 'rounded-s-sm' : 'rounded-sm'">
            {{ type }}
        </div>
        <div class=" text-stone-300 bg-stone-500 rounded-e-sm px-1.5 font-bold text-sm" :class="{ hidden: !area }">
            {{ area }}
        </div>
    </div>
</template>
<script setup>

const props = defineProps({
    type: String,
    area: String,
})

</script>