import { createRouter, createWebHistory } from "vue-router";

import AppTriple from "../pages/AppTriple.vue";

const routes = [
    { path: "/", name: "home", component: AppTriple },
    { path: "/triple-control", name: "triple-control", component: AppTriple },
    { path: "/projects", name: "projects", component: AppTriple },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

export default router;
