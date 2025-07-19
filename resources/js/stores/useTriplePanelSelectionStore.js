import { defineStore } from "pinia";
import { ref } from "vue";

export const useTriplePanelSelectionStore = defineStore(
    "triple-panel-selection",
    () => {
        const panelSelected = ref("admin");
        const prevPanel = ref(null);
        function setPanel(value) {
            prevPanel.value = panelSelected.value;
            panelSelected.value = value;
        }
        return { panelSelected, prevPanel, setPanel };
    },
);
