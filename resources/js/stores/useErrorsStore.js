import { defineStore } from "pinia";
import { ref } from "vue";

export const useErrorsStore = defineStore("errors", () => {
    const isError = ref(false);
    const messages = ref({});

    async function setErrors(errorMessages) {
        if (errorMessages && typeof errorMessages === "object") {
            isError.value = true;
            messages.value = {};
            for (const [field, msgs] of Object.entries(errorMessages)) {
                messages.value[field] = Array.isArray(msgs) ? msgs[0] : msgs;
            }
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
