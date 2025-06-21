<template>
    <div class="col-span-1 flex flex-col min-h-0">
        <Selector />
    </div>
    <div class="col-span-3 flex flex-col min-h-0 max-h-full overflow-hidden rounded-sm bg-stone-400">
        <SettingPanel />
    </div>
</template>
<script setup>
import Selector from './Selector.vue';
import SettingPanel from './SettingPanel.vue';


</script>