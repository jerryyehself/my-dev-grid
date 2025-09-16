<template>
    <section
        class="flex flex-col my-2"
        ref="wrapperRef"
        :class="{ 'min-h-0': expand }"
    >
        <div
            class="flex items-center justify-between text-xl font-bold cursor-pointer px-2"
            @click="$emit('toggle')"
        >
            <h2 class="">{{ title }}</h2>
            <ChevronDownIcon
                :class="[
                    'w-5 h-5 transition-transform duration-300',
                    { 'rotate-180': isOpen },
                ]"
                @click="isOpen = !isOpen"
            />
        </div>

        <div v-if="isOpen" class="flex flex-col min-h-0 flex-1 py-2">
            <ul
                v-if="filteredItems.length"
                class="divide-stone-400 overflow-auto flex-1 min-h-0 scroll-blend"
            >
                <li
                    v-for="(item, index) in filteredItems"
                    :key="index"
                    class="hover:bg-stone-400 hover:text-white text-stone-900 px-6 py-1 cursor-pointer text-sm"
                    :class="[
                        item.call_number == '00' ? 'font-bold' : '',
                        {
                            'bg-stone-400 text-white':
                                tripleSelection.selected?.item?.id == item.id &&
                                tripleSelection.selected?.title == title,
                        },
                    ]"
                    @click="handleItemClick(title, item)"
                >
                    <div class="flex flex-wrap gap-1">
                        <div class="">
                            {{ item.full_call_number }}
                        </div>
                        <div class="">
                            {{ item.name }}
                        </div>
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
import { useTripleSelectionStore } from "@/stores/useTripleSelectionStore";
import { ChevronDownIcon } from "@heroicons/vue/24/outline";

const props = defineProps({
    title: String,
    items: Array,
    isOpen: Boolean,
    expand: Boolean,
});
const emit = defineEmits(["toggle", "item"]);

const searchKeyword = ref("");
const wrapperRef = ref(null);
const tripleSelection = useTripleSelectionStore();

const itemList = computed(() => props.items || []);
const filteredItems = computed(() => {
    const keyword = searchKeyword.value.trim().toLowerCase();
    if (!keyword) return itemList.value;
    return itemList.value.filter(
        (item) =>
            (item.name && item.name.toLowerCase().includes(keyword)) ||
            (item.title && item.title.toLowerCase().includes(keyword)) ||
            (item.id && String(item.id).toLowerCase().includes(keyword)),
    );
});

function handleItemClick(title, item) {
    tripleSelection.setTripleSelection(title, item);
}

onMounted(() => {
    if (wrapperRef.value) autoAnimate(wrapperRef.value);
});
</script>
