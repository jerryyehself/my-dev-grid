<template>
    <fieldset class="flex justify-between items-center shrink-0">
        <select class="border rounded px-2 py-1 text-sm">
            <option value="subject">主動關係</option>
            <option value="object">被動關係</option>
            <option value="both">雙向關係</option>
        </select>
        <div v-if="detail.parent">
            <label class="text-sm flex items-center gap-1">
                <input type="checkbox" /> 顯示父層（基本）關聯
            </label>
        </div>
    </fieldset>

    <!-- 滾動區 -->
    <div class="flex-1 divide-y divide-stone-200 pr-1">
        <template
            v-for="relation in detail.subject_of"
            :key="'subject-' + relation.id"
        >
            <div class="flex items-center py-2 text-sm">
                <span class="w-1/3 truncate">{{ detail.name }}</span>
                <span class="w-1/3 text-center flex flex-col items-center">
                    <span>{{ relation.name }}</span>
                    <AppDirectionArrows width="60" height="20" />
                </span>
                <span class="w-1/3 text-right truncate">
                    {{ preload.scopesData.data[relation.object]?.name }}
                </span>
            </div>
        </template>

        <!-- 被動關係 -->
        <template
            v-for="relation in detail.object_of"
            :key="'object-' + relation.id"
        >
            <div class="flex items-center py-2 text-sm">
                <span class="w-1/3 truncate">
                    {{ preload.scopesData.data[relation.subject]?.name }}
                </span>
                <span class="w-1/3 text-center flex flex-col items-center">
                    <span>{{ relation.name }}</span>
                    <AppDirectionArrows width="60" height="20" />
                </span>
                <span class="w-1/3 text-right truncate">{{ detail.name }}</span>
            </div>
        </template>
    </div>
</template>
<script setup>
import AppDirectionArrows from "../icons/AppDirectionArrows.vue";
defineProps({
    detail: Object,
    preload: Object,
});
</script>
