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
import { ref, onMounted, watch } from "vue";
import AppTriplePanelNav from "../components/layouts/AppTriplePanelNav.vue";
import { useDataStore } from "../stores/useDataStore";
import { useFormsStore } from "../stores/useFormsStore";
import AppTriplePanelView from "../components/layouts/AppTriplePanelView.vue";

const panelSelected = ref("admin");
const prevPanel = ref(panelSelected.value);
const dataStore = useDataStore();
const formsStore = useFormsStore();

watch(panelSelected, (newVal, oldVal) => {
    prevPanel.value = oldVal;
});

onMounted(async () => {
    if (!dataStore.isLoaded) await dataStore.fetchData();
    if (!formsStore.isLoaded) await formsStore.fetchForms();
});
</script>
