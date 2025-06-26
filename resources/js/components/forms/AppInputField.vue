<template>
    <input
        v-if="['text', 'number'].includes(input.type)"
        :id="inputKey"
        :name="inputKey"
        :type="input.type"
        v-model="setValue"
        :placeholder="input.placeholder"
        :min="input.type === 'number' ? 0 : undefined"
        :max="input.type === 'number' ? 99 : undefined"
        :step="input.type === 'number' ? 1 : undefined"
        class="w-full px-3 py-2 rounded border border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition"
    />

    <div v-else-if="input.type === 'label'">
        <span>{{ setValue }}</span>
        <input
            type="hidden"
            :name="inputKey"
            :id="inputKey"
            :value="setValue"
        />
    </div>

    <div v-else-if="input.type === 'select'">
        {{ type }}
        <select
            :id="inputKey"
            :name="inputKey"
            v-model="setValue"
            class="w-full px-3 py-2 rounded border border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition"
        >
            <option
                v-for="(option, key) in input.options"
                :key="key"
                :value="type === 'relations' ? option.id : option.class_number"
            >
                {{ option.CURIE }}
            </option>
        </select>
    </div>

    <textarea
        v-else-if="input.type === 'textarea'"
        :id="inputKey"
        :name="inputKey"
        rows="4"
        :placeholder="input.placeholder"
        v-model="setValue"
        class="w-full px-3 py-2 rounded border border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition resize-y"
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
