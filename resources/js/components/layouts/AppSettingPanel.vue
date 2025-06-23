<script setup>
import AppTripleItemMetaTag from './AppTripleItemMetaTag.vue';
import AppInputUnit from '../forms/AppInputUnit.vue';
import { useSelectionStore } from '@/stores/selection';
import { computed, ref, watch } from 'vue';

const selection = useSelectionStore();
const target = computed(() => selection.selected);
const formRef = ref(null)
const form = ref(null)
const itemMeta = computed(() => ({
    type: target.value.type,
    area: target.value.item?.parent?.name
}))

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

// function submitForm() {
//     const formData = new FormData(formRef.value)
//     // 可以拿到所有 input 有 name 的欄位資料
//     for (const [key, value] of formData.entries()) {
//         console.log(key, value)
//     }
// }
</script>

<template>
    <header class="flex items-center text-xl font-bold text-stone-100 bg-stone-700 px-2 py-1">
        Setting
    </header>
    <form v-if="target.item" ref="formRef" @submit.prevent="handleSubmit"
        class="flex flex-col space-y-2 flex-1 min-h-0 p-2 mt-2">
        <legend class="flex flex-col  space-y-2">
            <div class="flex items-center space-x-2">
                <span class="text-xl font-bold">{{ target.item.name }}</span>
                <AppTripleItemMetaTag :itemMeta="itemMeta" />
            </div>

            <dl class="text-sm text-left space-y-1">
                <div class="flex justify-between w-60">
                    <dt class="text-gray-900 truncate">Updated at</dt>
                    <dd class="truncate">{{ target.item.updated_at }}</dd>
                </div>
                <div class="flex justify-between w-60">
                    <dt class="text-gray-900 truncate">Created at</dt>
                    <dd class="truncate">{{ target.item.created_at }}</dd>
                </div>
            </dl>
        </legend>

        <div class="flex flex-col gap-2 pr-1.5 flex-1 overflow-auto scroll-blend">
            <div v-for="(input, index) in form" :key="index" :class="[{ hidden: input.type === 'hidden' }, 'w-full']">
                <AppInputUnit :input="input" :inputKey="index" :target="target" :type="target.type" />
            </div>
        </div>
    </form>
    <div v-else class="h-full"></div>

    <footer class="flex justify-end text-stone-100 bg-stone-700 px-2 py-1 gap-2">
        <button class="rounded border-2 px-2">123</button>
        <button class="rounded border-2 px-2">123</button>
    </footer>
</template>