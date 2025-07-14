<template>
    <textarea
        :id="props.inputKey"
        :name="props.inputKey"
        rows="4"
        :placeholder="props.input.placeholder"
        v-model="model"
        class="p-1 resize-none"
    ></textarea>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
    input: Object,
    inputKey: String,
    modelValue: {
        type: [String, null],
        default: "",
    },
});

const emit = defineEmits(["update:modelValue"]);

const model = computed({
    get: () => props.modelValue,
    set: (val) => emit("update:modelValue", val),
});
</script>
