<template>
    <Transition :name="transitionName" mode="out-in">
        <template v-if="props.panelSelected == 'new'">
            <AppTripleNewPanel
                class="h-full w-full flex flex-col"
                @updatePanel="props.panelSelected = $event"
            />
        </template>
        <template v-else>
            <div
                class="col-span-5 grid grid-cols-5 min-h-0 max-h-full overflow-hidden h-full"
            >
                <section class="col-span-1 flex flex-col min-h-0 bg-stone-300">
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
</template>
<script setup>
import { ref, computed } from "vue";
import AppTripleNewPanel from "./AppTripleNewPanel.vue";
import AppTriplesNav from "./AppTriplesNav.vue";
import AppTripleDetail from "./AppTripleDetail.vue";

const props = defineProps({
    panelSelected: {
        type: String,
        required: true,
    },
});
const prevPanel = ref(props.panelSelected);

const transitionName = computed(() => {
    return prevPanel.value !== "new" && props.panelSelected === "new"
        ? "slide-left"
        : "slide-right";
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
    transform: translateX(-100%);
    opacity: 0;
}
.slide-left-leave-to {
    transform: translateX(100%);
    opacity: 0;
}
.slide-right-enter-from {
    transform: translateX(100%);
    opacity: 0;
}
.slide-right-leave-to {
    transform: translateX(-100%);
    opacity: 0;
}
</style>
