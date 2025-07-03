<template>
    <fieldset class="flex justify-between items-center shrink-0">
        <select
            class="border rounded px-2 py-1 text-sm"
            v-model="relationDirect"
            @change="onScrollCheck"
        >
            <option value="subject">主動關係</option>
            <option value="object">被動關係</option>
            <option value="both">雙向關係</option>
        </select>
        <div v-if="detail.parent">
            <label class="text-sm inline-flex items-center">
                <input
                    type="checkbox"
                    class="sr-only peer"
                    v-model="parentRelation"
                />
                <div
                    class="relative w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-1 peer-focus:ring-stone-300 dark:peer-focus:ring-stone-800 rounded-full peer dark:bg-stone-300 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-600 peer-checked:bg-stone-600 dark:peer-checked:bg-stone-600"
                ></div>
                <span class="ms-3 text-sm font-medium">
                    顯示父層（基本）關聯
                </span>
            </label>
        </div>
    </fieldset>

    <!-- 滾動區 -->
    <div class="flex-1 divide-y divide-stone-200 pr-1">
        <template
            v-if="relationDirect === 'subject'"
            v-for="relation in detail.subject_of"
            :key="'subject-' + relation.id"
        >
            <div class="flex items-center py-2 text-sm">
                <span class="w-1/3 truncate font-bold">{{ detail.name }}</span>
                <span class="w-1/3 text-center flex flex-col items-center">
                    <span>{{ relation.name }}</span>
                    <AppDirectionArrows width="60" height="20" />
                </span>
                <span class="w-1/3 text-right truncate font-bold">
                    {{ preload.scopesDict[relation.object]?.name }}
                </span>
            </div>
        </template>

        <!-- 被動關係 -->
        <template
            v-if="relationDirect === 'object'"
            v-for="relation in detail.object_of"
            :key="'object-' + relation.id"
        >
            <div class="flex items-center py-2 text-sm transform decoration-20">
                <span class="w-1/3 truncate font-bold">
                    {{ preload.scopesDict[relation.subject]?.name }}
                </span>
                <span class="w-1/3 text-center flex flex-col items-center">
                    <span>{{ relation.name }}</span>
                    <AppDirectionArrows width="60" height="20" />
                </span>
                <span class="w-1/3 text-right truncate font-bold">{{
                    detail.name
                }}</span>
            </div>
        </template>
        <!-- 雙向關係 -->
        <template
            v-if="relationDirect === 'both'"
            v-for="relation in detail.subject_of"
            :key="'both-' + relation.id"
        >
            <div
                v-if="relation.reverse_id"
                class="flex items-center py-2 text-sm transform decoration-20"
            >
                <span class="w-1/3 truncate font-bold">
                    {{ preload.scopesDict[relation.subject]?.name }}
                </span>
                <span class="w-1/3 text-center flex flex-col items-center">
                    <span>
                        {{ relation.name }} /
                        {{ preload.relationsDict[relation.reverse_id]?.name }}
                    </span>
                    <AppDirectionArrows
                        :direction="'both'"
                        width="60"
                        height="20"
                    />
                </span>
                <span class="w-1/3 text-right truncate font-bold">
                    {{ preload.scopesDict[relation.object]?.name }}
                </span>
            </div>
        </template>
        <template v-if="detail.parent && parentRelation">
            <!-- 父層主動 -->
            <template
                v-if="relationDirect === 'subject'"
                v-for="relation in detail.parent.subject_of"
                :key="'parent-subject-' + relation.id"
            >
                <div class="flex items-center py-2 text-sm">
                    <span class="w-1/3 truncate font-bold">{{
                        preload.scopesDict[relation.subject]?.name
                    }}</span>
                    <span class="w-1/3 text-center flex flex-col items-center">
                        <span>{{ relation.name }}</span>
                        <AppDirectionArrows width="60" height="20" />
                    </span>
                    <span class="w-1/3 text-right truncate font-bold">
                        {{ preload.scopesDict[relation.object]?.name }}
                    </span>
                </div>
            </template>
            <!-- 父層被動 -->
            <template
                v-if="relationDirect === 'object'"
                v-for="relation in detail.parent.subject_of"
                :key="'parent-object-' + relation.id"
            >
                <div class="flex items-center py-2 text-sm">
                    <span class="w-1/3 truncate font-bold">
                        {{ preload.scopesDict[relation.object]?.name }}
                    </span>
                    <span class="w-1/3 text-center flex flex-col items-center">
                        <span>{{ relation.name }}</span>
                        <AppDirectionArrows width="60" height="20" />
                    </span>
                    <span class="w-1/3 text-right truncate font-bold">
                        {{ preload.scopesDict[relation.subject]?.name }}
                    </span>
                </div>
            </template>
            <!-- 父層雙向 -->
            <template
                v-if="relationDirect === 'both'"
                v-for="relation in detail.parent.subject_of"
                :key="'parent-object-' + relation.id"
            >
                <div
                    v-if="relation.reverse_id"
                    class="flex items-center py-2 text-sm"
                >
                    <span class="w-1/3 truncate font-bold">{{
                        preload.scopesDict[relation.object]?.name
                    }}</span>
                    <span class="w-1/3 text-center flex flex-col items-center">
                        <span>
                            {{ relation.name }} /
                            {{
                                preload.relationsDict[relation.reverse_id]?.name
                            }}
                        </span>
                        <AppDirectionArrows
                            :direction="'both'"
                            width="60"
                            height="20"
                        />
                    </span>
                    <span class="w-1/3 text-right truncate font-bold">
                        {{ preload.scopesDict[relation.subject]?.name }}
                    </span>
                </div>
            </template>
        </template>
    </div>
</template>
<script setup>
import { ref, watch } from "vue";
import AppDirectionArrows from "../icons/AppDirectionArrows.vue";
import AppToggleUnit from "../forms/AppToggleUnit.vue";

const relationDirect = ref("both");
const parentRelation = ref(true);

const { detail, preload, onScrollCheck } = defineProps({
    detail: Object,
    preload: Object,
    onScrollCheck: Function,
});

watch(
    () => detail,
    () => {
        relationDirect.value = "both";
    },
    { deep: true },
);
</script>
