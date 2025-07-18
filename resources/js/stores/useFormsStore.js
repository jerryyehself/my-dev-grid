import { defineStore } from "pinia";
import { ref } from "vue";
import { fetchAPI } from "../utils/useFetchAPI";

export const useFormsStore = defineStore("useForms", () => {
    const scopesForm = ref(null);
    const relationsForm = ref(null);
    const isLoaded = ref(false);

    async function fetchForms() {
        const [scopes, relations] = await Promise.all([
            fetchAPI("/scopes/create"),
            fetchAPI("/relations/create"),
        ]);
        scopesForm.value = scopes;
        relationsForm.value = relations;
        isLoaded.value = true;
    }

    return {
        scopesForm,
        relationsForm,
        isLoaded,
        fetchForms,
    };
});
