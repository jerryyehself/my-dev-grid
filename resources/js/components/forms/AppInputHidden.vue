<template>
    <input
        class="px-1"
        type="hidden"
        :id="props.inputKey"
        :name="props.inputKey"
        v-model="setValue"
        :placeholder="props.input.placeholder"
    />
</template>
<script setup>
import { computed } from "vue";
const props = defineProps({
    input: Object,
    inputKey: String,
    modelValue: {
        type: [String, Number, null],
        default: "",
    },
});

const emit = defineEmits(["update:modelValue"]);

const setValue = computed({
    get: () => props.modelValue,
    set: (val) => emit("update:modelValue", val),
});
</script>
