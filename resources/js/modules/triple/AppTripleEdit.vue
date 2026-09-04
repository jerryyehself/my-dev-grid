<template>
    <form
        class="flex flex-col col-span-5 items-center flex-1 p-5 gap-5 min-h-0"
        ref="form"
        @submit.prevent="onSubmit"
    >
        <div class="flex flex-col p-2 w-1/2">
            <span class="text-sm text-stone-500">Editing {{ editingType }}</span>
            <h3 class="text-lg font-bold text-stone-800">
                {{ originalName || "..." }}
            </h3>
        </div>

        <div class="flex flex-col flex-1 min-h-0 w-1/2 p-2">
            <span>Set Properties</span>
            <div
                class="bg-stone-200 flex-1 p-5 flex flex-col h-full min-h-0 overflow-hidden"
            >
                <div class="flex-1 overflow-auto scroll-m-1 scroll-blend">
                    <p v-if="isLoading" class="text-stone-500">Loading...</p>
                    <p v-else-if="loadError" class="text-red-500">
                        Failed to load this record for editing.
                    </p>
                    <div
                        v-for="(field, index) in formFields"
                        v-else
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
                <div class="flex items-end justify-center gap-2">
                    <AppWidgetButton :button="cancelButton" />
                    <AppWidgetButton :button="submitButton" />
                </div>
            </div>
        </div>
    </form>
</template>

<script setup>
import { ref, computed, reactive, watch, h, onMounted, nextTick } from "vue";
import { storeToRefs } from "pinia";
import { useFormsStore } from "@/stores/useFormsStore";
import AppInputField from "../../components/forms/AppInputField.vue";
import AppWidgetButton from "../../components/widgets/AppWidgetButton.vue";
import { CheckCircleIcon, XCircleIcon } from "@heroicons/vue/16/solid";
import { useDataStore } from "@/stores/useDataStore";
import { fetchAPI } from "../../utils/useFetchAPI";
import { useTriplePanelSelectionStore } from "@/stores/useTriplePanelSelectionStore";
import { useErrorsStore } from "@/stores/useErrorsStore";
import { useTripleSelectionStore } from "../../stores/useTripleSelectionStore";

// 編輯目標固定為進入此畫面當下 useTripleSelectionStore 選取的 triple；
// 跟 AppTripleNew.vue 不同，這裡不能中途切換類型（type 不可變）。
const tripleSelection = useTripleSelectionStore();
const { selected } = storeToRefs(tripleSelection);
const editingType = selected.value?.title ?? "";
const editingId = selected.value?.item?.id ?? null;
const originalName = ref(selected.value?.item?.name ?? "");

const formScopeData = reactive({
    id: "",
    name: "",
    class_number: "",
    call_number: "",
    comment: "",
    note: "",
});

const formRelationData = reactive({
    id: "",
    name: "",
    subject_id: "",
    object_id: "",
    class_number: "",
    call_number: "",
    note: "",
});

const formData = computed(() =>
    editingType === "scopes" ? formScopeData : formRelationData,
);

const preload = useFormsStore();
const scopesData = useDataStore().scopesData?.data ?? [];
const relationData = useDataStore().relationsData?.data ?? [];

// Update 端點（UpdateScopeRequest）預期的 class_number 就是實際存好的數值，
// 不像「新增」表單的 class_number 欄位其實是「挑一個既有頂層 Scope，代入它
// 的 class_number」這種間接選擇（那個轉換只有 ScopeController@store 在做，
// update() 沒有）。所以編輯表單沿用「新增」表單同一份欄位設定/驗證顯示，
// 只把 scope 的 class_number 欄位型別由 select 換成 number，直接編輯真正
// 要送出的數值。
const formFields = computed(() => {
    const base = preload[`${editingType}Form`] || {};
    if (editingType !== "scopes" || !base.class_number) return base;
    return {
        ...base,
        class_number: { ...base.class_number, type: "number", options: undefined },
    };
});

const isLoading = ref(true);
const loadError = ref(false);
// 表單載入時會把 subject_id/object_id 從空字串異動成後端回傳的實際值，
// 這個異動本身就會被下面的 watcher 偵測到；沒有這個旗標的話，一開啟編輯畫面
// 就會誤觸發「重新推算 class_number/call_number」的邏輯，把既有值覆蓋掉。
const isPopulating = ref(true);

const emit = defineEmits(["updatePanel"]);

onMounted(async () => {
    useErrorsStore().setErrors();

    if (!editingType || !editingId) {
        loadError.value = true;
        isLoading.value = false;
        return;
    }

    try {
        const detail = await fetchAPI(`/api/${editingType}/${editingId}`);
        originalName.value = detail?.name ?? originalName.value;

        if (editingType === "scopes") {
            formScopeData.id = detail.id;
            formScopeData.name = detail.name ?? "";
            formScopeData.class_number = detail.class_number ?? "";
            formScopeData.call_number = detail.call_number ?? "";
            formScopeData.comment = detail.comment ?? "";
            formScopeData.note = detail.note ?? "";
        } else {
            formRelationData.id = detail.id;
            formRelationData.name = detail.name ?? "";
            formRelationData.subject_id = detail.subject ?? "";
            formRelationData.object_id = detail.object ?? "";
            formRelationData.class_number = detail.class_number ?? "";
            formRelationData.call_number = detail.call_number ?? "";
            formRelationData.note = detail.note ?? "";
        }
    } catch (error) {
        console.error("Failed to load record for editing:", error);
        loadError.value = true;
    } finally {
        isLoading.value = false;
        await nextTick();
        isPopulating.value = false;
    }
});

// Relation 的 class_number/call_number 是由 subject/object 兩個 Scope 推導
// 出來的，跟「新增」表單同一套邏輯，編輯時换了 subject/object 一樣要重新推算。
// 只有在表單載入完成、使用者實際改動 subject/object 之後才會觸發（見上面
// isPopulating 的說明），避免一開啟編輯畫面就把既有的 call_number 覆蓋掉。
watch(
    () => [formData.value.subject_id, formData.value.object_id],
    async ([subjectId, objectId]) => {
        if (isPopulating.value || editingType !== "relations" || !subjectId || !objectId) return;
        const subjectScope = scopesData.find(
            (scope) => scope.id === Number(subjectId),
        );
        const objectScope = scopesData.find(
            (scope) => scope.id === Number(objectId),
        );
        const newClass =
            (subjectScope?.class_number?.charAt(0) ?? "") +
            (objectScope?.class_number?.charAt(0) ?? "");

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
    const response = await fetch(`/api/${editingType}/${classCode}`);
    const data = await response.json();
    return data.new_child_call_number ?? "";
}

const submitButton = {
    color: "bg-stone-600",
    label: "save",
    value: "save",
    ability: ref(true),
    icon: h(CheckCircleIcon, { class: "w-4 h-4" }),
    action: () => {},
};

const cancelButton = {
    color: "bg-stone-400",
    label: "cancel",
    value: "cancel",
    ability: ref(true),
    icon: h(XCircleIcon, { class: "w-4 h-4" }),
    action: () => {
        useTriplePanelSelectionStore().setPanel("admin");
        return true;
    },
};

function onSubmit() {
    if (!editingType || !editingId) return;

    fetchAPI(`/api/${editingType}/${editingId}`, {
        method: "PUT",
        body: JSON.stringify({ ...formData.value }),
    })
        .then((response) => {
            const updated = response?.data;
            useDataStore().fetchData();
            if (updated) {
                useTripleSelectionStore().setTripleSelection(editingType, updated);
            }
            useTriplePanelSelectionStore().setPanel("admin");
        })
        .catch((error) => {
            console.error("Error submitting form:", error);
        });
}
</script>
