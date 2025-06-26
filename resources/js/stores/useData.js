import { defineStore } from "pinia";
import { ref } from "vue";
import { fetchAPI } from "../fetchAPI";

export const useData = defineStore("useData", () => {
    const scopesData = ref(null);
    const relationsData = ref(null);
    const isLoaded = ref(false);
    async function fetchData() {
        scopesData.value = await fetchAPI("/api/scopes");
        relationsData.value = await fetchAPI("/api/relations");
        isLoaded.value = true;
    }

    return {
        scopesData,
        relationsData,
        isLoaded,
        fetchData,
    };
});
