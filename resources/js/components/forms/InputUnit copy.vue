<template>
    <label :for="inputKey" class="block mb-1 font-semibold text-stone-900 text-lg">
        {{ input.label }}
    </label>
    <input v-if="['text', 'number'].includes(input.type)" :id="inputKey" :name="inputKey" :type="input.type"
        v-model="setValue" :placeholder="input.placeholder"
        class="w-full px-3 py-2 rounded border border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition" />

    <div v-else-if="input.type == 'label'">
        <span>{{ setValue }}</span>
        <input :id="inputKey" :name="inputKey" type="hidden" v-model="setValue" />
    </div>
    <select v-else-if="input.type === 'select'" :id="inputKey" :name="inputKey" v-model="setValue"
        class="w-full px-3 py-2 rounded border border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition">
        <option v-for="(option, key) in input.options" :key="key"
            :value="(props.type == 'relation' ? option.id : option.class_number)">
            {{ option.CURIE }}
        </option>
    </select>
    <textarea v-else-if="input.type === 'textarea'" :id="inputKey" :name="inputKey" rows="4"
        :placeholder="input.placeholder" v-model="setValue"
        class="w-full px-3 py-2 rounded border border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition resize-y">
        </textarea>
</template>
<script setup>
import { computed } from "vue";
import { defineStore } from 'pinia';
import Input from 'Input.vue';
import Select from 'Select.vue';
import Textarea from 'Textarea.vue';
import Hidden from 'Hidden.vue';

const props = defineProps({
    input: Object,
    inputKey: String,
    target: Array,
    type: String
})

const setValue = computed({
    get() {
        const value = props.target.item[props.inputKey]
        console.log(value)
        return typeof value === 'object' && value !== null ? value.id : value
    },
    set(newVal) {
        props.target.item[props.inputKey] = newVal
    }
})
</script>