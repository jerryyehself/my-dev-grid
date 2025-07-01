<template>
    <AppHeader
        class="h-20 bg-gradient-to-b from-stone-900 to-stone-700 text-stone-200 grid grid-cols-4 items-center p-2 box-border shadow-black shadow-sm relative z-10"
    />
    <main class="flex-1 grid grid-cols-5 min-h-0 box-border">
        <section class="col-span-1 flex flex-col min-h-0 bg-stone-300">
            <AppTriplesNav />
        </section>

        <section
            class="col-span-4 flex flex-col min-h-0 max-h-full overflow-hidden"
        >
            <AppTripleDetail class="flex-1 overflow-auto" />
        </section>

        <!-- <section
            class="col-span-1 flex flex-col min-h-0 max-h-full overflow-hidden"
        >
            <AppSettingPanel />
        </section> -->
    </main>
</template>
<script setup>
import AppTriplesNav from "./layouts/AppTriplesNav.vue";
import AppSettingPanel from "./layouts/AppSettingPanel.vue";
import AppHeader from "./layouts/AppHeader.vue";
import AppTripleDetail from "./layouts/AppTripleDetail.vue";
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
