<template>
    <form
        class="flex flex-col col-span-5 items-center flex-1 p-5 gap-5 min-h-0"
    >
        <label class="flex flex-col p-2 w-1/2">
            Step 1. Selected Triple
            <select
                class="bg-stone-300 rounded-md border border-stone-400 px-4 py-2 w-full focus:border-3"
                v-model="tripleSelected"
            >
                <option value="scope">Scope</option>
                <option value="relation">Relation</option>
            </select>
        </label>

        <div class="flex flex-col flex-1 min-h-0 w-1/2 p-2">
            <span>Step 2. Set Attributes</span>
            <div
                class="bg-stone-300 flex-1 rounded-md border border-stone-400 p-5 overflow-auto flex flex-col h-full"
            >
                <div
                    v-for="(field, index) in formFields"
                    :key="index"
                    :class="{ 'mb-2': field.label }"
                >
                    <label class="grid grid-cols-[4rem_1fr] items-center gap-2">
                        <span class="text-sm font-medium text-gray-700">
                            {{ field?.label }}
                        </span>
                        <input
                            class="rounded-md bg-white border border-stone-400 px-2 py-1 text-sm w-full"
                            :type="field.type"
                        />
                    </label>
                </div>
            </div>
        </div>
    </form>
</template>
<script setup>
import { ref, computed } from "vue";
import { useForms } from "@/stores/useForms";

const preload = useForms();
const tripleSelected = ref("scope");

const formFields = computed(() => preload[`${tripleSelected.value}sForm`]);
</script>
