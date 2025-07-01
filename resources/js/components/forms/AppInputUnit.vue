<template>
    <div class="flex flex-col mb-3">
        <label
            v-if="input.label"
            :for="inputKey"
            class="block mb-1 font-semibold text-stone-900 text-lg"
        >
            {{ input.label }}
        </label>

        <AppInputField
            :input="input"
            :inputKey="inputKey"
            :type="type"
            :modelValue="target[inputKey]"
            @update:modelValue="(val) => updateField(val)"
            class="w-full px-3 py-2 rounded border bg-white dark:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition"
            :class="[
                { 'resize-y': type === 'textarea' },
                errorMessages[inputKey] 
                    ? 'text-rose-700 dark:text-rose-400 border-rose-600 dark:border-rose-500 bg-rose-100 dark:bg-rose-950 placeholder-rose-400 dark:placeholder-rose-500'
                    : 'text-stone-900 dark:text-stone-200 border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 placeholder-stone-400 dark:placeholder-stone-500',
            ]"
        />
        <span
            v-if="errorMessages[inputKey]"
            class="text-sm text-red-600 font-semibold"
        >
            {{ errorMessages[inputKey] }}
        </span>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { useErrors } from "@/stores/useErrors";
import AppInputField from "./AppInputField.vue";

const errors = useErrors();
const errorMessages = computed(() => errors.messages);

const props = defineProps({
    input: Object,
    inputKey: String,
    target: Object,
    type: String,
});

const emit = defineEmits(["update:target"]);

function updateField(newVal) {
    const updated = { ...props.target, [props.inputKey]: newVal };
    emit("update:target", updated);
}
</script>
