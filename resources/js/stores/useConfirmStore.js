import { defineStore } from "pinia";
import { ref } from "vue";

export const useConfirmStore = defineStore("confirm", () => {
    const isVisible = ref(false);
    const message = ref("");
    let resolver = null;

    function show(msg) {
        message.value = msg;
        isVisible.value = true;
        return new Promise((resolve) => {
            resolver = resolve; // 把 resolve 保存起來
        });
    }

    function confirm() {
        isVisible.value = false;
        resolver?.(true);
    }

    function cancel() {
        isVisible.value = false;
        resolver?.(false);
    }

    return { isVisible, message, show, confirm, cancel };
});
