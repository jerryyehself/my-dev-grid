import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useSelectionStore = defineStore('selection', () => {
    const selected = ref({ title: '', item: null, type: null });
    function setSelection(title, item, type) {
        selected.value = { title, item, type };
    }

    return { selected, setSelection };
});