<template>
    <div class="flex flex-col flex-1 justify-between">
        <ul>
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
    </div>
</template>

<script setup>
import { ref } from "vue";

const selectedNav = ref("Metadata");
const sections = ["Metadata", "Relationship", "Statistic"];

function currentSelectedDetailNav(section) {
    selectedNav.value = section;
}
</script>
