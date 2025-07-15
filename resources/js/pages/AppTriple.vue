<template>
    <div class="flex flex-col h-full">
        <AppTriplePanelNav v-model="panelSelected" />
        <AppTriplePanelView
            :panel-selected="panelSelected"
            class="flex-1 h-full min-h-0 max-h-full overflow-hidden"
        />
    </div>
</template>

<script setup>
import { ref, onMounted, watch, computed } from "vue";
import AppTriplePanelNav from "../components/layouts/AppTriplePanelNav.vue";
import { useData } from "../stores/useData";
import { useForms } from "../stores/useForms";
import AppTriplePanelView from "../components/layouts/AppTriplePanelView.vue";

const panelSelected = ref("admin");
const prevPanel = ref(panelSelected.value);
const dataStore = useData();
const formsStore = useForms();

watch(panelSelected, (newVal, oldVal) => {
    prevPanel.value = oldVal;
});

onMounted(async () => {
    if (!dataStore.isLoaded) await dataStore.fetchData();
    if (!formsStore.isLoaded) await formsStore.fetchForms();
});
</script>
