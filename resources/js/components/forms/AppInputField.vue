<template>
    <label :class="['grid grid-cols-[4rem_1fr] items-center']">
        <span class="text-sm font-medium text-gray-700">
            {{ input.label }}
        </span>
        <component
            :is="fieldComponentMap[input.type]"
            :input="input"
            :input-key="inputKey"
            v-model="setValue"
            :class="[
                'bg-white border rounded',
                errors[inputKey]
                    ? 'border-red-500 text-red-500 '
                    : 'border-stone-400 rounded',
            ]"
        />
        <span v-if="errors[inputKey]" class="text-red-500 text-sm col-start-2">
            {{ errors[inputKey] }}
        </span>
    </label>
</template>

<script setup>
defineOptions({ inheritAttrs: false });
import { computed } from "vue";
import { useErrorsStore } from "@/stores/useErrorsStore";

import AppInputText from "../forms/AppInputText.vue";
import AppInputNumber from "../forms/AppInputNumber.vue";
import AppInputSelect from "../forms/AppInputSelect.vue";
import AppInputTextArea from "../forms/AppInputTextArea.vue";
import AppInputLabel from "../forms/AppInputLabel.vue";
import AppInputHidden from "../forms/AppInputHidden.vue";

const errors = computed(() => useErrorsStore().messages);

const props = defineProps({
    input: Object,
    inputKey: String,
    target: Object,
    type: String,
    modelValue: [String, Number, Object],
});
const fieldComponentMap = {
    text: AppInputText,
    number: AppInputNumber,
    select: AppInputSelect,
    textarea: AppInputTextArea,
    label: AppInputLabel,
    hidden: AppInputHidden,
};
const emit = defineEmits(["update:modelValue"]);

const setValue = computed({
    get: () => props.modelValue,
    set: (val) => emit("update:modelValue", val),
});
</script>
