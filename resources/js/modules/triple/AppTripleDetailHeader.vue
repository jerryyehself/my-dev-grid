<template>
    <header class="flex flex-col">
        <!-- <AppTripleItemMetaTag :itemMeta="itemMeta" /> -->
        <div class="mx-5 mt-5 flex justify-between h-16">
            <div class="flex flex-col justify-center">
                <span class="text-sm">{{ itemMeta.type }}</span>
                <h2 class="text-2xl font-extrabold text-stone-800">
                    {{ props.detail.name }}
                </h2>
            </div>
            <div class="flex gap-4 p-2 items-center">
                <AppWidgetButton
                    v-for="button in buttonConfigs"
                    :key="button.label"
                    :button="button"
                    class="h-8"
                />
            </div>
        </div>
    </header>
</template>

<script setup>
import { computed, h, ref } from "vue";
import { DocumentArrowUpIcon, TrashIcon } from "@heroicons/vue/24/outline";
import AppWidgetButton from "../../components/widgets/AppWidgetButton.vue";
import { useConfirmStore } from "@/stores/useConfirmStore";

import AppTripleItemMetaTag from "./AppTripleItemMetaTag.vue";

const props = defineProps({
    target: {
        type: Object,
        required: true,
    },
    detail: {
        type: Object,
        required: true,
    },
});

const itemMeta = computed(() => ({
    type: props.target?.title,
    area: props.target?.item?.parent?.name,
}));

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
        ability: computed(() => !!props.target?.item?.parent),
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
