import { createRouter, createWebHistory } from "vue-router";

import AppTriple from "../pages/AppTriple.vue";

const routes = [
    { path: "/triple-control", name: "triple-control", component: AppTriple },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

export default router;
