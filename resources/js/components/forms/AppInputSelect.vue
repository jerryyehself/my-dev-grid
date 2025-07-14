<template>
    <select :id="props.inputKey" :name="props.inputKey" v-model="setValue">
        <option
            v-for="(option, key) in props.input.options"
            :key="key"
            :value="option.id"
        >
            {{ option.CURIE }}
        </option>
    </select>
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
