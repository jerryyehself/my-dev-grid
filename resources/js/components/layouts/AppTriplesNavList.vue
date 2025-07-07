<template>
    <section class="flex flex-col" ref="wrapper" :class="{ 'min-h-0': expand }">
        <div
            class="flex items-center justify-between text-xl font-bold px-2 py-1 cursor-pointer"
            :class="{ 'shadow-lg shadow-grey': expand }"
            @click="$emit('toggle')"
        >
            <h2>{{ title }}</h2>
            <ChevronDownIcon
                :class="[
                    'w-5 h-5 transition-transform duration-300',
                    { 'rotate-180': isOpen },
                ]"
                @click="isOpen = !isOpen"
            />
        </div>

        <div v-if="isOpen" class="flex flex-col min-h-0 flex-1 box-border">
            <ul
                v-if="filteredList.length"
                class="divide-y divide-stone-400 overflow-auto flex-1 min-h-0 scroll-blend"
            >
                <li
                    v-for="(item, index) in filteredList"
                    :key="index"
                    class="hover:border-stone-400 hover:border-l-8 text-stone-900 px-2 py-1 cursor-pointer text-sm pr-1.5"
                    :class="[
                        item.call_number == '00' ? 'font-bold' : '',
                        {
                            'bg-white':
                                selection.selected?.item?.id == item.id &&
                                selection.selected?.title == title,
                        },
                    ]"
                    @click="onItemClick(title, item)"
                >
                    <div class="flex gap-1">
                        <span class="w-1/5 px-1">
                            {{ item.full_call_number }}
                        </span>
                        <span class="w-4/5">
                            {{ item.name }}
                        </span>
                    </div>
                </li>
            </ul>
            <p v-else class="text-stone-500">not available</p>
        </div>
    </section>
</template>

<script setup>
import { ref, computed, onMounted } from "vue";
import autoAnimate from "@formkit/auto-animate";
import { useSelectionStore } from "@/stores/useSelectionStore";
import { ChevronDownIcon } from "@heroicons/vue/24/outline";

const selection = useSelectionStore();

function onItemClick(title, item) {
    selection.setSelection(title, item);
}

const props = defineProps({
    title: String,
    items: Array,
    isOpen: Boolean,
    expand: Boolean,
});

const emit = defineEmits(["toggle", "item"]);

const search = ref("");
const wrapper = ref(null);

const list = computed(() => props.items || []);

const filteredList = computed(() => {
    if (!search.value) return list.value;
    const keyword = search.value.toLowerCase();
    return list.value.filter(
        (item) =>
            (item.name && item.name.toLowerCase().includes(keyword)) ||
            (item.title && item.title.toLowerCase().includes(keyword)) ||
            (item.id && String(item.id).toLowerCase().includes(keyword)),
    );
});

onMounted(() => {
    if (wrapper.value) autoAnimate(wrapper.value);
});
</script>
