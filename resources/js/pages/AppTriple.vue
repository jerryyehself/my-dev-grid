<template>
    <section class="grid grid-cols-2 shadow-sm border-b-1 border-stone-400">
        <AppPanelNav v-model="panelSelected" />
    </section>
    <main
        class="relative flex-1 grid grid-cols-5 min-h-0 box-border overflow-hidden h-full"
    >
        <Transition :name="transitionName" mode="out-in">
            <template v-if="panelSelected == 'new'">
                <AppTripleNewPanel
                    class="h-full w-full flex flex-col"
                    @updatePanel="panelSelected = $event"
                />
            </template>
            <template v-else>
                <div
                    class="col-span-5 grid grid-cols-5 min-h-0 max-h-full overflow-hidden h-full"
                >
                    <section
                        class="col-span-1 flex flex-col min-h-0 bg-stone-300"
                    >
                        <AppTriplesNav />
                    </section>
                    <section
                        class="col-span-4 flex flex-col min-h-0 max-h-full overflow-hidden h-full"
                    >
                        <AppTripleDetail class="flex-1 overflow-auto" />
                    </section>
                </div>
            </template>
        </Transition>
    </main>
</template>

<script setup>
import { ref, onMounted, watch, computed } from "vue";
import AppPanelNav from "../components/layouts/AppPanelNav.vue";
import AppTriplesNav from "../components/layouts/AppTriplesNav.vue";
import AppTripleDetail from "../components/layouts/AppTripleDetail.vue";
import AppTripleNewPanel from "../components/layouts/AppTripleNewPanel.vue";
import { useData } from "../stores/useData";
import { useForms } from "../stores/useForms";
import AppHeader from "../components/layouts/AppHeader.vue";

const panelSelected = ref("admin");
const prevPanel = ref(panelSelected.value);
const dataStore = useData();
const formsStore = useForms();

const transitionName = computed(() => {
    if (prevPanel.value === "new" && panelSelected.value !== "new") {
        return "slide-left";
    } else if (prevPanel.value !== "new" && panelSelected.value === "new") {
        return "slide-right";
    }
    return "slide-left";
});

watch(panelSelected, (newVal, oldVal) => {
    prevPanel.value = oldVal;
});

onMounted(async () => {
    if (!dataStore.isLoaded) await dataStore.fetchData();
    if (!formsStore.isLoaded) await formsStore.fetchForms();
});
</script>

<style scoped>
.slide-left-enter-active,
.slide-left-leave-active,
.slide-right-enter-active,
.slide-right-leave-active {
    transition:
        transform 0.3s ease,
        opacity 0.3s ease;
}

.slide-left-enter-from {
    transform: translateX(100%);
    opacity: 0;
}
.slide-left-leave-to {
    transform: translateX(-100%);
    opacity: 0;
}
.slide-right-enter-from {
    transform: translateX(-100%);
    opacity: 0;
}
.slide-right-leave-to {
    transform: translateX(100%);
    opacity: 0;
}
</style>
