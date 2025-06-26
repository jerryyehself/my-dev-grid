import { defineStore } from "pinia";
import { ref } from "vue";
import { fetchAPI } from "../fetchAPI";

export const useForms = defineStore("useForms", () => {
    const scopesForm = ref(null);
    const relationsForm = ref(null);
    const isLoaded = ref(false);
    async function fetchForms() {
        scopesForm.value = await fetchAPI("/scopes/create");
        relationsForm.value = await fetchAPI("/relations/create");
        isLoaded.value = true;
    }

    return {
        scopesForm,
        relationsForm,
        isLoaded,
        fetchForms,
    };
});
