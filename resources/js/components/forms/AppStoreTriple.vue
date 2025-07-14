<template>
    <label
        :class="[
            'grid grid-cols-[4rem_1fr] items-center gap-2',
            errors[index] &&
                'border border-red-500 text-red-500 rounded-sm p-2',
        ]"
    >
        <span class="text-sm font-medium text-gray-700">
            {{ field?.label }}
        </span>
        <component
            :is="fieldComponentMap[field.type]"
            :key="index"
            :input-key="index"
            :input="field"
            v-model="formData[field.name]"
            class="rounded-sm bg-white border-1 border-stone-400"
        />
        <!-- <AppInputField
                            :input="field"
                            :input-key="index"
                            :type="field.type"
                            :select="field.select"
                            :click="field.click"
                            class="rounded-sm bg-white border-1 border-stone-400"
                            v-model="formData[index]"
                        /> -->
    </label>
    <span v-if="errors[index]" class="text-red-500">
        {{ errors[index] }}
    </span>
</template>
<script setup>
import AppInputText from "../forms/AppInputText.vue";
import AppInputNumber from "../forms/AppInputNumber.vue";
import AppInputSelect from "../forms/AppInputSelect.vue";
import AppInputTextArea from "../forms/AppInputTextArea.vue";
import AppInputLabel from "../forms/AppInputLabel.vue";
import AppInputHidden from "../forms/AppInputHidden.vue";
import { useForms } from "../../stores/useForms";
const fieldComponentMap = {
    text: AppInputText,
    number: AppInputNumber,
    select: AppInputSelect,
    textarea: AppInputTextArea,
    label: AppInputLabel,
    hidden: AppInputHidden,
};
</script>
