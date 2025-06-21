<script setup>
import { ref, onMounted } from 'vue';
import List from './List.vue';

const scopes = ref({ title: 'scopes', items: [] });
const relations = ref({ title: 'relations', items: [] });

const openList = ref('scopes');

onMounted(async () => {
    const [scopeRes, relationRes] = await Promise.all([
        fetch('/api/scopes', {
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        }),
        fetch('/api/relations', {
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        }),
    ]);

    let scope = await scopeRes.json();
    let relation = await relationRes.json();

    scopes.value.items = scope.data;
    scopes.value.type = scope.type
    relations.value.items = relation.data;
    relations.value.type = relation.type
});

function handleToggle(listName) {
    openList.value = openList.value === listName ? '' : listName;
}
</script>
<template>
    <nav class="flex flex-col space-y-2 max-h-full min-h-0 overflow-hidden">
        <List title="scopes" :items="scopes.items" :type="scopes.type" :is-open="openList === 'scopes'"
            @toggle="handleToggle('scopes')" :expand="openList === 'scopes'" />
        <List title="relations" :items="relations.items" :type="relations.type" :is-open="openList === 'relations'"
            @toggle="handleToggle('relations')" :expand="openList === 'relations'" />
    </nav>
</template>