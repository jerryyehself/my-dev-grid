import { defineStore } from "pinia";
import { ref } from "vue";
import { fetchAPI } from "../fetchAPI";

export const useData = defineStore("useData", () => {
    const scopesData = ref(null);
    const relationsData = ref(null);
    const scopesDict = ref(null);
    const relationsDict = ref(null);
    const isLoaded = ref(false);

    async function fetchData() {
        const scopes = await fetchAPI("/api/scopes");
        scopesData.value = scopes;
        scopesDict.value = scopes?.data
            ? Object.fromEntries(scopes.data.map((scope) => [scope.id, scope]))
            : {};

        const relations = await fetchAPI("/api/relations");
        relationsData.value = relations;
        relationsDict.value = relations?.data
            ? Object.fromEntries(relations.data.map((rel) => [rel.id, rel]))
            : {};

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
