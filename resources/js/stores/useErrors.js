import { defineStore } from "pinia";
import { ref } from "vue";

export const useErrors = defineStore("useErrors", () => {
    const isError = ref(false);
    const messages = ref({});

    async function setErrors(errorMessages) {
        if (errorMessages && typeof errorMessages === "object") {
            isError.value = true;
            // 清空舊訊息，避免殘留
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
