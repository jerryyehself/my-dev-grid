<template>
    <section class="col-span-1 flex flex-col min-h-0">
        <AppTriplesNav />
    </section>
    <section class="col-span-2 flex flex-col min-h-0 max-h-full overflow-hidden rounded-sm bg-stone-400"></section>
    <section class="col-span-1 flex flex-col min-h-0 max-h-full overflow-hidden rounded-sm bg-stone-400">
        <AppSettingPanel />
    </section>
</template>
<script setup>
import AppTriplesNav from './layouts/AppTriplesNav.vue';
import AppSettingPanel from './layouts/AppSettingPanel.vue';


</script>