<template>
    <section class="col-span-1 flex flex-col min-h-0">
        <AppTriplesNav />
    </section>
    <section
        class="col-span-2 flex flex-col min-h-0 max-h-full overflow-hidden rounded-sm bg-stone-400"
    ></section>
    <section
        class="col-span-1 flex flex-col min-h-0 max-h-full overflow-hidden rounded-sm bg-stone-400"
    >
        <AppSettingPanel />
    </section>
</template>
<script setup>
import AppTriplesNav from "./layouts/AppTriplesNav.vue";
import AppSettingPanel from "./layouts/AppSettingPanel.vue";
import { useData } from "../stores/useData";
import { onMounted } from "vue";
import { useForms } from "../stores/useForms";

const dataStore = useData();
const formsStore = useForms();

onMounted(async () => {
    if (!dataStore.isLoaded) await dataStore.fetchData();
    if (!formsStore.isLoaded) await formsStore.fetchForms();
});
</script>
