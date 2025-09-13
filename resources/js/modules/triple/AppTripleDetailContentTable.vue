<template>
    <ul
        class="flex gap-4 py-2 border-b border-stone-200 text-sm text-stone-600 overflow-x-auto"
    >
        <li
            v-for="section in sections"
            :key="section"
            class="hover:text-shadow-sm"
        >
            <a
                :href="'#' + section"
                @click="currentSelectedDetailNav(section)"
                :class="[
                    'text-gray-400',
                    {
                        'text-shadow-sm': selectedNav === section,
                    },
                ]"
            >
                # {{ section }}
            </a>
        </li>
    </ul>
</template>
<script setup>
import { ref } from "vue";

const selectedNav = ref("Metadata");
const sections = ["Metadata", "Relationship", "Statistic"];

function currentSelectedDetailNav(section) {
    selectedNav.value = section;
}
</script>
