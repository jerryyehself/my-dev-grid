<template>
    <div
        @click="props.click"
        class="h-6.5 px-1 border border-stone-400 rounded bg-white flex items-center"
    >
        <span>{{ setValue }}</span>
        <input
            type="hidden"
            :name="props.inputKey"
            :id="props.inputKey"
            v-model="setValue"
        />
    </div>
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

const setValue = computed({
    get: () => props.modelValue,
    set: (val) => emit("update:modelValue", val),
});
</script>
