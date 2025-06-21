<script setup>
import ItemMetaTag from './ItemMetaTag.vue';
import Input from './forms/Input.vue';
import { useSelectionStore } from '@/stores/selection';
import { computed, ref, watch } from 'vue';

const selection = useSelectionStore();
const target = computed(() => selection.selected);
const form = ref(null)

watch(
    () => target.value.type,
    async (type) => {
        if (!type) return
        console.log('fetching...', type)

        try {
            const res = await fetch(`./${type}s/create`, {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            })
            form.value = (await res.json()).form
        } catch (err) {
            console.error('Fetch failed:', err)
        }
    },
    { immediate: true }
)
</script>

<template>
    <div class="flex items-center text-xl font-bold text-stone-100 bg-stone-700 px-2 py-1">
        Setting
    </div>
    <div class="flex flex-col space-y-2 flex-1 min-h-0 overflow-hidden p-2">
        <div v-if="target.item" class="flex flex-col flex-1 min-h-0">
            <div class="flex space-x-2 items-center">
                <h2 class="text-xl font-bold">{{ target.item.name }}</h2>
                <div class="">
                    <ItemMetaTag :type="target.type" :area="target.item.parent?.name" />
                </div>
            </div>
            <div v-for="(input, key) in form" :key="key" :class="input.type == 'hidden' ? 'hidden' : ''">
                <Input :input="input" :inputKey="key" :key="key" :target="target" />
            </div>
        </div>
    </div>
</template>