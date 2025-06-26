// usePreload.ts
import { useForms } from "@/stores/useForms";
import { useData } from "@/stores/useData";

export async function usePreload() {
    const forms = useForms();
    const data = useData();

    await Promise.all([forms.fetchData?.(), data.fetchData?.()]);
}
