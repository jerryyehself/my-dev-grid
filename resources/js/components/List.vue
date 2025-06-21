<template>
    <div class="flex flex-col" ref="wrapper" :class="{ 'flex-1 min-h-0': expand }">
        <div class="flex items-center justify-between text-xl font-bold text-stone-100 bg-stone-700 px-2 py-1 cursor-pointer"
            :class="isOpen ? 'rounded-t-sm' : 'rounded-sm'" @click="$emit('toggle')">
            <h2>{{ title }}</h2>
            <svg :class="['w-5 h-5 transition-transform duration-300', { 'rotate-180': isOpen }]" fill="none"
                stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </div>

        <div v-if="isOpen" class="bg-stone-400 p-2 rounded-b-sm flex flex-col min-h-0 flex-1">
            <input type="text" v-model="search"
                class="border border-stone-500 rounded px-1 py-0.5 w-full mb-1 focus:outline-none focus:ring-2 focus:ring-yellow-800"
                placeholder="search..." />

            <ul v-if="filteredList.length" class="divide-y divide-stone-300 overflow-auto flex-1 min-h-0 scroll-blend">
                <li v-for="(item, index) in filteredList" :key="index"
                    class="hover:bg-yellow-800 hover:text-white text-stone-900 px-2 py-1 cursor-pointer"
                    :class="(item.call_number == '00') ? 'font-bold' : ''" @click="onItemClick(title, item, type)">
                    {{ item.CURIE }}
                </li>
            </ul>
            <p v-else class="text-stone-500">not available</p>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import autoAnimate from '@formkit/auto-animate'
import { useSelectionStore } from '@/stores/selection';

const selection = useSelectionStore();

function onItemClick(title, item, type) {
    selection.setSelection(title, item, type);
}

const props = defineProps({
    title: String,
    type: String,
    items: Array,
    isOpen: Boolean,
    expand: Boolean,
})

const emit = defineEmits(['toggle', 'item'])

const search = ref('')
const wrapper = ref(null)

const list = computed(() => props.items || [])

const filteredList = computed(() => {
    if (!search.value) return list.value
    const keyword = search.value.toLowerCase()
    return list.value.filter(item =>
        (item.name && item.name.toLowerCase().includes(keyword)) ||
        (item.title && item.title.toLowerCase().includes(keyword)) ||
        (item.id && String(item.id).toLowerCase().includes(keyword))
    )
})

onMounted(() => {
    if (wrapper.value) autoAnimate(wrapper.value)
})

</script>