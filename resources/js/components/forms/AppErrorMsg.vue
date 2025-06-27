<template>
    <div class="flex flex-col mb-3">{{}}</div>
</template>

<script setup>
import AppInputField from "./AppInputField.vue";

const props = defineProps({
    error: Object,
    inputKey: String,
});

const emit = defineEmits(["update:target"]);

function updateField(newVal) {
    const updated = { ...props.target, [props.inputKey]: newVal };
    emit("update:target", updated);
}
</script>
