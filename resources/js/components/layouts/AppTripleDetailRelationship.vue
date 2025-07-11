<template>
    <fieldset class="flex justify-between items-center shrink-0">
        <select
            class="border rounded px-2 py-1 text-sm"
            v-model="relationDirect"
            @change="props.onScrollCheck"
        >
            <option value="subject">主動關係</option>
            <option value="object">被動關係</option>
            <option value="both">雙向關係</option>
        </select>
        <div v-if="hasParent">
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
        <RelationRow
            v-for="row in relationRows"
            :key="row.key"
            v-bind="row.props"
        />
    </div>
</template>

<script setup>
import { ref, watch, computed } from "vue";
import AppDirectionArrows from "../icons/AppDirectionArrows.vue";
import AppToggleUnit from "../forms/AppToggleUnit.vue";

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
    preload: {
        type: Object,
        required: true,
    },
    onScrollCheck: {
        type: Function,
        required: false,
    },
});

const relationDirect = ref("both");
const parentRelation = ref(true);

const subjectOf = computed(() => props.detail?.subject_of ?? []);
const objectOf = computed(() => props.detail?.object_of ?? []);
const parentSubjectOf = computed(() => props.detail?.parent?.subject_of ?? []);
const hasParent = computed(() => !!props.detail?.parent);

// 將所有顯示邏輯統一轉為 relationRows 陣列
const relationRows = computed(() => {
    const rows = [];
    // 主動關係
    if (relationDirect.value === "subject") {
        rows.push(
            ...subjectOf.value.map((relation) => ({
                key: "subject-" + relation.id,
                props: {
                    type: "subject",
                    relation,
                    detail: props.detail,
                    preload: props.preload,
                },
            })),
        );
        if (hasParent.value && parentRelation.value) {
            rows.push(
                ...parentSubjectOf.value.map((relation) => ({
                    key: "parent-subject-" + relation.id,
                    props: {
                        type: "parent-subject",
                        relation,
                        detail: props.detail,
                        preload: props.preload,
                    },
                })),
            );
        }
    }
    // 被動關係
    if (relationDirect.value === "object") {
        rows.push(
            ...objectOf.value.map((relation) => ({
                key: "object-" + relation.id,
                props: {
                    type: "object",
                    relation,
                    detail: props.detail,
                    preload: props.preload,
                },
            })),
        );
        if (hasParent.value && parentRelation.value) {
            rows.push(
                ...parentSubjectOf.value.map((relation) => ({
                    key: "parent-object-" + relation.id,
                    props: {
                        type: "parent-object",
                        relation,
                        detail: props.detail,
                        preload: props.preload,
                    },
                })),
            );
        }
    }
    // 雙向關係
    if (relationDirect.value === "both") {
        rows.push(
            ...subjectOf.value
                .filter((r) => r.reverse_id)
                .map((relation) => ({
                    key: "both-" + relation.id,
                    props: {
                        type: "both",
                        relation,
                        detail: props.detail,
                        preload: props.preload,
                    },
                })),
        );
        if (hasParent.value && parentRelation.value) {
            rows.push(
                ...parentSubjectOf.value
                    .filter((r) => r.reverse_id)
                    .map((relation) => ({
                        key: "parent-both-" + relation.id,
                        props: {
                            type: "parent-both",
                            relation,
                            detail: props.detail,
                            preload: props.preload,
                        },
                    })),
            );
        }
    }
    return rows;
});

watch(
    () => props.detail,
    () => {
        relationDirect.value = "both";
    },
    { deep: true },
);

// 動態元件：根據 type 顯示不同 row 樣板（使用 Vue SFC 標準語法）
import RelationRow from "./AppRelationRow.vue";
</script>
