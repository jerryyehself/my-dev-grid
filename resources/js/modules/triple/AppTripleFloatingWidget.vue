<template>
    <div
        class="fixed p-4 right-0 bottom-10 z-50 flex flex-col w-full max-w-[160px] rounded-l-md shadow-md shadow-gray-500 bg-white/80 backdrop-blur-sm border border-stone-300"
    >
        <h4 class="pb-2 font-bold text-center text-shadow-lg">Action</h4>
        <div class="flex flex-col gap-2">
            <AppWidgetButton
                v-for="button in buttonConfigs"
                :key="button.label"
                :button="button"
            />
        </div>
    </div>
</template>

<script setup>
import { h, computed } from "vue";
import AppWidgetButton from "../../components/widgets/AppWidgetButton.vue";
import { DocumentArrowUpIcon, TrashIcon } from "@heroicons/vue/24/outline";
import { fetchAPI } from "../../utils/useFetchAPI";
import { useDataStore } from "@/stores/useDataStore";
import { useTripleSelectionStore } from "@/stores/useTripleSelectionStore";
import { useConfirmStore } from "@/stores/useConfirmStore";

const props = defineProps({
    target: {
        type: [Object, null],
        required: true,
    },
});

const buttonConfigs = [
    {
        label: "Modify",
        color: "bg-stone-500",
        icon: h(DocumentArrowUpIcon, { class: "w-4 h-4" }),
        ability: computed(() => !!props.target.item),
        action: () => setAction("update"),
    },
    {
        label: "Delete",
        color: "bg-red-400",
        icon: h(TrashIcon, { class: "w-4 h-4" }),
        ability: computed(() => !!props.target.item?.parent),
        action: async () => {
            const confirm = useConfirmStore();
            const confirmed = await confirm.show("確定要刪除嗎？");
            if (confirmed) {
                fetchAPI(`/api/${props.target.title}/${props.target.item.id}`, {
                    method: "DELETE",
                })
                    .then(({ status, body }) => {
                        useDataStore().fetchData();
                        useTripleSelectionStore().setTripleSelection(
                            props.target.title,
                            null,
                        );
                    })
                    .catch((error) => {
                        console.error("Error submitting form:", error);
                    });
            }
            return true;
        },
    },
];
</script>
