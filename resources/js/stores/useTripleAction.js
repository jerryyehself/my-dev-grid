import { defineStore } from "pinia";
import { ref } from "vue";

export const useTripleAction = defineStore("useTripleAction", () => {
    const action = ref(null);

    function setAction(val) {
        action.value = val;
    }

    return {
        action,
        setAction,
    };
});
