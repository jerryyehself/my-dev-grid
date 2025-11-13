import { createRouter, createWebHistory } from "vue-router";

import AppTriple from "../pages/AppTriple.vue";
import AppProjects from "../pages/AppProjects.vue";
import AppHome from "../pages/AppHome.vue";
import AppAboutMe from "../pages/AppAboutMe.vue";

const routes = [
    { path: "/", name: "home", component: AppHome },
    { path: "/triple-control", name: "triple-control", component: AppTriple },
    { path: "/projects", name: "projects", component: AppProjects },
    { path: "/about", name: "about-me", component: AppAboutMe },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

export default router;
