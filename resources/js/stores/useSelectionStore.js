import { defineStore } from "pinia";
import { ref } from "vue";

export const useSelectionStore = defineStore("useSelectionStore", () => {
    const selected = ref({ title: "", item: null });
    function setSelection(title, item) {
        selected.value = { title, item };
    }

    return { selected, setSelection };
});
