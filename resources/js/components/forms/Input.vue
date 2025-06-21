<template>
    <div class="flex flex-col space-y-1 mb-2">
        <label :for="inputKey" class="block mb-1 font-semibold text-stone-900 text-lg">
            {{ input.label }}
        </label>

        <input v-if="['text', 'number'].includes(input.type)" :id="inputKey" :name="inputKey" :type="input.type"
            v-model="target.item[inputKey]" :placeholder="input.placeholder"
            class="px-3 py-2 rounded border border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition" />

        <select v-else-if="input.type === 'select'" :id="inputKey" :name="inputKey" :value="target.item[inputKey]"
            v-model="target.item[inputKey]"
            class="px-3 py-2 rounded border border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition">
            <option v-for="(option, key) in input.options" :key="key" :value="option.class_number">
                {{ option.CURIE }}
            </option>
        </select>
        <textarea v-else-if="input.type === 'textarea'" :id="inputKey" :name="inputKey" rows="4"
            :placeholder="input.placeholder" v-model="target.item[inputKey]"
            class="px-3 py-2 rounded border border-stone-300 dark:border-stone-600 bg-white dark:bg-stone-800 text-stone-900 dark:text-stone-100 focus:outline-none focus:ring-2 focus:ring-yellow-700 focus:border-transparent transition resize-y">
        </textarea>
    </div>
</template>
<script setup>
defineProps({
    input: Object,
    inputKey: String,
    target: Array
})
</script>