<template>
    <footer class="py-5 px-4">
        <dl class="grid grid-cols-2 gap-2 text-gray-600 text-sm">
            <div>
                <dt class="font-medium text-gray-900">Created at</dt>
                <dd>{{ detail.created_at }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-900">Updated at</dt>
                <dd>{{ detail.updated_at }}</dd>
            </div>
        </dl>
    </footer>
</template>
<script setup>
defineProps({
    detail: Object,
});
</script>
