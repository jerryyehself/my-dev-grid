<template>
    <div class="flex gap-3 justify-between text-sm">
        <div v-if="!detail.parent">
            <dt>子類數量</dt>
            <dd>{{ detail.children.length }}</dd>
        </div>
        <div v-else>
            <dt>兄弟數量</dt>
            <dd>{{ detail.siblings.length }}</dd>
        </div>
        <div>
            <dt>關聯數量</dt>
            <dd>{{ detail.object_of.length + detail.subject_of.length }}</dd>
        </div>
        <div>
            <dt>記錄數量</dt>
            <dd></dd>
        </div>
    </div>
</template>
<script setup>
defineProps({
    detail: Object,
});
</script>
