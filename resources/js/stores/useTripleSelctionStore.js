import { defineStore } from "pinia";
import { ref } from "vue";
import { useErrors } from "./useErrorsStore";

export const useTripleSelctionStore = defineStore("triple-selction", () => {
    const selected = ref({ title: "", item: null, CURIE: "" });
    function setTripleSelction(title, item) {
        if (item && selected.value.CURIE !== item.CURIE) {
            selected.value = { title, item, CURIE: item.CURIE };
            useErrors().setErrors();
        } else {
            selected.value = { title, item: null, CURIE: "" };
        }
    }

    return {
        selected,
        setTripleSelction,
    };
});
