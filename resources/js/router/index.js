import { createRouter, createWebHistory } from "vue-router";

// import Home from "../Home.vue";
// import About from "../pages/About.vue";
import AppTriple from "../pages/AppTriple.vue";

const routes = [
    // { path: "/", name: "home", component: Home },
    { path: "/triple-control", name: "triple-control", component: AppTriple },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

export default router;
