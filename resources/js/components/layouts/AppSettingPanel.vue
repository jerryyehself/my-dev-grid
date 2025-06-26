<script setup>
import AppTripleItemMetaTag from "./AppTripleItemMetaTag.vue";
import AppInputUnit from "../forms/AppInputUnit.vue";
import { useSelectionStore } from "@/stores/useSelectionStore";
import { computed, ref, watch } from "vue";
import { useForms } from "@/stores/useForms";
import { useData } from "../../stores/useData";

const selection = useSelectionStore();
const target = computed(() => selection.selected);
const formRef = ref(null);
const form = ref(null);
const itemMeta = computed(() => ({
    type: target.value.title,
    area: target.value.item?.parent?.name,
}));

const preload = useForms();
const originTargetName = ref("");

watch(
    () => selection.selected,
    (newSelected) => {
        if (newSelected) {
            originTargetName.value = newSelected?.item?.name;
        }
    },
    { immediate: true },
);

watch(
    () => target.value.title,
    async (type) => {
        if (!type) return;
        form.value = preload[`${type}Form`];
    },
    { immediate: true },
);

async function submitForm() {
    const formData = JSON.stringify(target.value.item);

    await fetch(`./api/${target.value.title}/${target.value.item.id}`, {
        method: "PUT",
        body: formData,
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
        },
    });

    useData().fetchData();
}
</script>

<template>
    <header
        class="flex items-center text-xl font-bold text-stone-100 bg-stone-700 px-2 py-1"
    >
        Setting
    </header>
    <form
        v-if="target.item"
        ref="formRef"
        @submit.prevent="handleSubmit"
        class="flex flex-col space-y-2 flex-1 min-h-0 p-2 mt-2"
        id="aa"
    >
        <legend class="flex flex-col space-y-2">
            <div class="flex items-center space-x-2">
                <span class="text-xl font-bold">{{ originTargetName }}</span>
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

        <div
            class="flex flex-col gap-2 pr-1.5 flex-1 overflow-auto scroll-blend"
        >
            <div
                v-for="(input, index) in form"
                :key="index"
                :class="[{ hidden: input.type === 'hidden' }, 'w-full']"
            >
                <AppInputUnit
                    :input="input"
                    :inputKey="index"
                    v-model:target="target.item"
                    :type="target.title"
                />
            </div>
        </div>
    </form>
    <div v-else class="h-full"></div>

    <footer
        class="flex justify-end text-stone-100 bg-stone-700 px-2 py-1 gap-2"
    >
        <button class="rounded border-2 px-2" @click="submitForm">
            submit
        </button>
    </footer>
</template>
