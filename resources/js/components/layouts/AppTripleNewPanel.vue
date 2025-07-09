<template>
    <form
        class="flex flex-col col-span-5 items-center flex-1 p-5 gap-5 min-h-0"
        ref="form"
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
                        <AppInputField
                            :input="field"
                            :input-key="index"
                            :type="field.type"
                            :select="field.select"
                            :click="field.click"
                            class="rounded-sm bg-white border-1 border-stone-400"
                            v-model="formData[index]"
                        />
                    </label>
                </div>
                <div class="flex items-end justify-center flex-1">
                    <AppWidgetButton :button="submitButton" />
                </div>
            </div>
        </div>
    </form>
</template>

<script setup>
import { ref, computed, reactive, watch } from "vue";
import { useForms } from "@/stores/useForms";
import AppInputField from "../forms/AppInputField.vue";
import AppWidgetButton from "../widgets/AppWidgetButton.vue";

const formData = reactive({
    name: "",
    class_number: "",
    call_number: "",
    comment: "",
    note: "",
});
const tripleSelected = ref("scope");
const preload = useForms();

const formFields = computed(() => preload[`${tripleSelected.value}sForm`]);

const submitButton = {
    color: "bg-stone-600",
    label: "submit",
    value: "submit",
    ability: ref(true),
    action: () => {},
};

watch(
    () => formData.class_number,
    async (newClass) => {
        formData.call_number = await fetchCallNumberByClass(newClass);
    },
    { deep: true },
);

async function fetchCallNumberByClass(classCode) {
    if (!classCode) return "";
    const response = await fetch(`/api/${tripleSelected.value}s/${classCode}`);
    const data = await response.json();
    return data.new_child_call_number ?? "";
}
</script>
