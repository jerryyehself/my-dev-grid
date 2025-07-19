import { defineStore } from "pinia";
import { ref } from "vue";
import { useErrorsStore } from "./useErrorsStore";

export const useTripleSelectionStore = defineStore("triple-selection", () => {
    const selected = ref({ title: "", item: null, CURIE: "" });
    function setTripleSelection(title, item) {
        if (!item) {
            selected.value = { title, item: null, CURIE: "" };
            return;
        }

        if (selected.value.CURIE !== item.CURIE) {
            selected.value = { title, item, CURIE: item.CURIE };
            useErrorsStore().setErrors();
            return;
        }
    }

    return {
        selected,
        setTripleSelection,
    };
});
