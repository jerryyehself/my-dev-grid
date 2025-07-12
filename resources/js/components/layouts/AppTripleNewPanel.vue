<template>
    <form
        class="flex flex-col col-span-5 items-center flex-1 p-5 gap-5 min-h-0"
        ref="form"
        @submit.prevent="onSubmit"
    >
        <label class="flex flex-col p-2 w-1/2">
            Step 1. Selected Triple isLoaded,
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
import { ref, computed, reactive, watch, h } from "vue";
import { useForms } from "@/stores/useForms";
import AppInputField from "../forms/AppInputField.vue";
import AppWidgetButton from "../widgets/AppWidgetButton.vue";
import { CheckCircleIcon } from "@heroicons/vue/16/solid";
import { useData } from "../../stores/useData";
import { fetchAPI } from "../../fetchAPI";

const formData = reactive({
    name: "",
    class_number: "",
    call_number: "",
    comment: "",
    note: "",
});
const tripleSelected = ref("scope");
const preload = useForms();
const scopesData = useData().scopesData.data;
const relationData = useData().relationsData.data;

const formFields = computed(() => preload[`${tripleSelected.value}sForm`]);

const submitButton = {
    color: "bg-stone-600",
    label: "submit",
    value: "submit",
    ability: ref(true),
    icon: h(CheckCircleIcon, { class: "w-4 h-4" }),
    action: () => {},
};

watch(
    () => formData.class_number,
    async (newClass) => {
        formData.call_number = await fetchCallNumberByClass(newClass);
    },
    { deep: true },
);

watch(
    () => [formData.subject, formData.object],
    async ([subject, object]) => {
        if (!subject || !object) return;
        const newClass =
            scopesData[subject]?.class_number.charAt(0) +
            scopesData[object]?.class_number.charAt(0);

        const classId = relationData.find(
            (item) =>
                item.class_number === newClass && item.call_number == "00",
        )?.id;

        const relationCallNumber = await fetchCallNumberByClass(classId);

        formData.class_number = newClass;
        formData.call_number = relationCallNumber;
    },
);

async function fetchCallNumberByClass(classCode) {
    if (!classCode) return "";
    const response = await fetch(`/api/${tripleSelected.value}s/${classCode}`);
    const data = await response.json();
    return data.new_child_call_number ?? "";
}

function onSubmit() {
    fetchAPI(
        `/api/${tripleSelected.value}s`,
        {
            method: "POST",
            body: JSON.stringify(formData),
        },
        { showError: true },
    )
        .then((response) => {
            console.log("Form submitted successfully:", response);
            // Reset form data after successful submission
            Object.keys(formData).forEach((key) => {
                formData[key] = "";
            });
        })
        .catch((error) => {
            console.error("Error submitting form:", error);
        });
}
</script>
