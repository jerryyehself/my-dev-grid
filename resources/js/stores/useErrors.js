import { defineStore } from "pinia";
import { ref } from "vue";
export const useErrors = defineStore("useErrors", () => {
    const isError = ref(false);
    const messages = ref({});
    async function setErrors(errorMessages) {
        if (errorMessages) {
            isError.value = true;

            Object.entries(errorMessages).forEach(([field, [msg]]) => {
                messages.value[field] = msg;
            });
        } else {
            isError.value = false;
            messages.value = {};
        }
    }

    return {
        isError,
        messages,
        setErrors,
    };
});
