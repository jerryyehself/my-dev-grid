import { defineStore } from "pinia";
import { ref } from "vue";
import { useErrors } from "./useErrors";

export const useSelectionStore = defineStore("useSelectionStore", () => {
    const selected = ref({ title: "", item: null, CURIE: "" });
    function setSelection(title, item) {
        if (item && selected.value.CURIE !== item.CURIE) {
            selected.value = { title, item, CURIE: item.CURIE };
            useErrors().setErrors();
        } else {
            selected.value = { title, item: null, CURIE: "" };
        }
    }

    return {
        selected,
        setSelection,
    };
});
