import { defineStore } from "pinia";
import { ref } from "vue";
import { useErrors } from "./useErrors";
import { useSelectionStore } from "./useSelectionStore";

export const useTripleAction = defineStore("useTripleAction", () => {
    const action = ref(null);
    function setAction(action) {
        action.value = action;
    }

    return { action, setAction };
});
