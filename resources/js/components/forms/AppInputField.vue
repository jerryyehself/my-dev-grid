<template>
    <input
        v-if="['text', 'number'].includes(input.type)"
        class="px-1"
        :id="inputKey"
        :name="inputKey"
        :type="input.type"
        v-model="setValue"
        :placeholder="input.placeholder"
        :min="input.type === 'number' ? 0 : undefined"
        :max="input.type === 'number' ? 99 : undefined"
        :step="input.type === 'number' ? 1 : undefined"
    />

    <div
        v-else-if="input.type === 'label'"
        @click="click"
        class="h-6.5 px-1 border border-stone-400 rounded bg-white flex items-center"
    >
        <span>{{ setValue }}</span>
        <input
            type="hidden"
            :name="inputKey"
            :id="inputKey"
            v-model="setValue"
        />
    </div>

    <select
        v-else-if="input.type === 'select'"
        :id="inputKey"
        :name="inputKey"
        v-model="setValue"
    >
        <option
            v-for="(option, key) in input.options"
            :key="key"
            :value="option.id"
        >
            {{ option.CURIE }}
        </option>
    </select>

    <textarea
        v-else-if="input.type === 'textarea'"
        :id="inputKey"
        :name="inputKey"
        rows="4"
        :placeholder="input.placeholder"
        v-model="setValue"
    ></textarea>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
    input: Object,
    inputKey: String,
    target: Object,
    type: String,
    modelValue: [String, Number, Object],
});

const emit = defineEmits(["update:modelValue"]);

const setValue = computed({
    get: () => props.modelValue,
    set: (val) => emit("update:modelValue", val),
});
</script>
