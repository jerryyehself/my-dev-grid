<template>
    <div class="flex items-center py-2 text-sm relationship-row">
        <span class="w-1/3 truncate font-bold">
            <!-- 主動/被動/雙向/父層型態動態顯示 -->
            <template v-if="type === 'subject'">
                {{ detail.name }}
            </template>
            <template v-else-if="type === 'object'">
                {{ preload.scopesDict[relation.subject]?.name }}
            </template>
            <template v-else-if="type === 'both'">
                {{ preload.scopesDict[relation.subject]?.name }}
            </template>
            <template v-else-if="type === 'parent-subject'">
                {{ preload.scopesDict[relation.subject]?.name }}
            </template>
            <template v-else-if="type === 'parent-object'">
                {{ preload.scopesDict[relation.object]?.name }}
            </template>
            <template v-else-if="type === 'parent-both'">
                {{ preload.scopesDict[relation.object]?.name }}
            </template>
        </span>
        <span class="w-1/3 text-center flex flex-col items-center">
            <template v-if="type === 'both' || type === 'parent-both'">
                <span>
                    {{ relation.name }} /
                    {{ preload.relationsDict[relation.reverse_id]?.name }}
                </span>
                <AppDirectionArrows
                    :direction="'both'"
                    width="60"
                    height="20"
                />
            </template>
            <template v-else>
                <span>{{ relation.name }}</span>
                <AppDirectionArrows width="60" height="20" />
            </template>
        </span>
        <span class="w-1/3 text-right truncate font-bold">
            <template v-if="type === 'subject'">
                {{ preload.scopesDict[relation.object]?.name }}
            </template>
            <template v-else-if="type === 'object'">
                {{ detail.name }}
            </template>
            <template v-else-if="type === 'both'">
                {{ preload.scopesDict[relation.object]?.name }}
            </template>
            <template v-else-if="type === 'parent-subject'">
                {{ preload.scopesDict[relation.object]?.name }}
            </template>
            <template v-else-if="type === 'parent-object'">
                {{ preload.scopesDict[relation.subject]?.name }}
            </template>
            <template v-else-if="type === 'parent-both'">
                {{ preload.scopesDict[relation.subject]?.name }}
            </template>
        </span>
    </div>
</template>

<script setup>
import AppDirectionArrows from "../../components/icons/AppDirectionArrows.vue";
const props = defineProps({
    type: String,
    relation: Object,
    detail: Object,
    preload: Object,
});
</script>
