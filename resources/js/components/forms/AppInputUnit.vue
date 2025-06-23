<template>
    <div class="flex flex-col mb-3" :class="input.class?.w">
        <label v-if="input.label" :for="inputKey" class="block mb-1 font-semibold text-stone-900 text-lg">
            {{ input.label }}
        </label>

        <AppInputField :input="input" :inputKey="inputKey" :target="target" :type="type" />
    </div>
</template>

<script setup>
import AppInputField from './AppInputField.vue'

defineProps({
    input: Object,
    inputKey: String,
    target: Object,
    type: String,
})
</script>