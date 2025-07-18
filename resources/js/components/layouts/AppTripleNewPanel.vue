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
                class="bg-stone-300 flex-1 rounded-md border border-stone-400 p-5 flex flex-col h-full min-h-0 overflow-hidden"
            >
                <Transition name="fade" mode="out-in">
                    <div
                        :key="tripleSelected"
                        class="flex-1 overflow-auto scroll-m-1 scroll-blend"
                    >
                        <div
                            v-for="(field, index) in formFields"
                            :key="index"
                            :class="{ 'mb-2': field.label }"
                        >
                            <AppInputField
                                :input="field"
                                :input-key="index"
                                :type="field.type"
                                :select="field.select"
                                :click="field.click"
                                class="rounded-sm bg-white border-1 border-stone-400"
                                v-model="formData[index]"
                            />
                        </div>
                    </div>
                </Transition>
                <div class="flex items-end justify-center">
                    <AppWidgetButton :button="submitButton" />
                </div>
            </div>
        </div>
    </form>
</template>

<script setup>
import { ref, computed, reactive, watch, h } from "vue";
import { useFormsStore } from "@/stores/useFormsStore";
import AppInputField from "../forms/AppInputField.vue";
import AppWidgetButton from "../widgets/AppWidgetButton.vue";
import { CheckCircleIcon } from "@heroicons/vue/16/solid";
import { useDataStore } from "@/stores/useDataStore";
import { fetchAPI } from "../../useFetchAPI";
import { useTripleSelectionStore } from "@/stores/useTripleSelectionStore";
import { useErrorsStore } from "@/stores/useErrorsStore";

const formScopeData = reactive({
    name: "",
    class_number: "",
    call_number: "",
    comment: "",
    note: "",
});

const formRelationData = reactive({
    name: "",
    subject: "",
    object: "",
    call_number: "",
    comment: "",
});
const tripleSelected = ref("scope");
const formData = computed(() => {
    useErrorsStore().setErrors();
    return tripleSelected.value === "scope" ? formScopeData : formRelationData;
});
const emit = defineEmits(["updatePanel"]);

const preload = useFormsStore();
const scopesData = useDataStore().scopesData.data;
const relationData = useDataStore().relationsData.data;

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
    () => formData.value.class_number,
    async (newClass) => {
        if (tripleSelected.value !== "scope") return;
        formData.value.call_number = await fetchCallNumberByClass(newClass);
    },
    { deep: true },
);

watch(
    () => [formData.value.subject, formData.value.object],
    async ([subject, object]) => {
        if (!subject || !object) return;
        const newClass =
            scopesData[subject - 1]?.class_number.charAt(0) +
            scopesData[object - 1]?.class_number.charAt(0);

        const classId = relationData.find(
            (item) =>
                item.class_number === newClass && item.call_number == "00",
        )?.id;

        const relationCallNumber = classId
            ? await fetchCallNumberByClass(classId)
            : "00";

        formData.value.class_number = newClass;
        formData.value.call_number = relationCallNumber;
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
            body: JSON.stringify({ ...formData.value }),
        },
        { showError: true },
    )
        .then(({ status, body }) => {
            if (status === 201) {
                useDataStore().fetchData();
                useTripleSelectionStore().setTripleSelection(
                    `${tripleSelected.value}s`,
                    body.data,
                );
                emit("updatePanel", "admin");
            }

            Object.keys(formData).forEach((key) => {
                formData[key] = "";
            });
        })
        .catch((error) => {
            console.error("Error submitting form:", error);
        });
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
