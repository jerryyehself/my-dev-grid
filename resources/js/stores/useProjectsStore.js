import { defineStore } from "pinia";
import { ref } from "vue";
import { fetchAPI } from "../utils/useFetchAPI";

export const useProjectsStore = defineStore("useProjects", () => {
    // const scopesForm = ref(null);
    // const relationsForm = ref(null);
    const projectsData = ref(null);
    // const isLoaded = ref(false);
    const isLoaded = ref(false);

    async function fetchProjects() {
        const projects = await Promise.all([fetchAPI("/api/projects")]);
        projectsData.value = projects;
        isLoaded.value = true;
    }

    return {
        projectsData,
        isLoaded,
        fetchProjects,
    };
});
