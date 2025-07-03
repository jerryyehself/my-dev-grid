import { defineStore } from "pinia";
import { ref } from "vue";
import { fetchAPI } from "../fetchAPI";

export const useData = defineStore("useData", () => {
    const scopesData = ref(null);
    const scopesDict = ref(null);
    const relationsData = ref(null);
    const relationsDict = ref(null);
    const isLoaded = ref(false);
    async function fetchData() {
        scopesData.value = await fetchAPI("/api/scopes");

        scopesDict.value = Object.fromEntries(
            scopesData.value.data.map((scope) => [scope.id, scope]),
        );

        relationsData.value = await fetchAPI("/api/relations");

        relationsDict.value = Object.fromEntries(
            relationsData.value.data.map((scope) => [scope.id, scope]),
        );
        isLoaded.value = true;
    }

    return {
        scopesData,
        scopesDict,
        relationsData,
        relationsDict,
        isLoaded,
        fetchData,
    };
});
