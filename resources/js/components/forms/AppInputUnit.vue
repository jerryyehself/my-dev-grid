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
        />
    </div>
</template>

<script setup>
import AppInputField from "./AppInputField.vue";

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
