<template>
    <div class="col-span-5 grid grid-cols-5 min-h-0 max-h-full overflow-hidden">
        <section class="col-span-1 flex flex-col min-h-0 bg-stone-300">
            <AppTriplesNav />
        </section>
        <section
            class="col-span-4 flex flex-col min-h-0 max-h-full overflow-hidden"
        >
            <AppTripleDetail class="flex-1 overflow-auto" />
        </section>
    </div>
</template>
<script setup>
import AppTriplesNav from "../components/layouts/AppTriplesNav.vue";
import AppTripleDetail from "../components/layouts/AppTripleDetail.vue";
</script>
