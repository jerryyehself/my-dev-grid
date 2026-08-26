import { defineStore } from "pinia";
import { ref } from "vue";
import { useErrorsStore } from "./useErrorsStore";

export const useTripleSelectionStore = defineStore("triple-selection", () => {
    const selected = ref({ title: "", item: null });
    function setTripleSelection(title, item) {
        if (!item) {
            selected.value = { title, item: null };
            return;
        }

        if (selected.value.item?.id !== item.id) {
            selected.value = { title, item };
            useErrorsStore().setErrors();
            return;
        }
    }

    return {
        selected,
        setTripleSelection,
    };
});
