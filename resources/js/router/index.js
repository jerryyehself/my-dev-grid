import { createRouter, createWebHistory } from "vue-router";

import AppTriple from "../pages/AppTriple.vue";
import AppProjects from "../pages/AppProjects.vue";
import AppLogin from "../pages/AppLogin.vue";

const routes = [
    { path: "/", name: "home", component: AppTriple },
    { path: "/triple-control", name: "triple-control", component: AppTriple },
    { path: "/projects", name: "projects", component: AppProjects },
    { path: "/login", name: "login", component: AppLogin },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// SocialAuthController::callback() 導拒絕的 OAuth 登入到
// `/?auth_error=not_authorized`（見 my-dev-grid PR #32）；統一在這裡轉成
// 登入頁自己的 query 參數，讓錯誤顯示只集中在登入頁一個地方處理。
router.beforeEach((to) => {
    if (to.query.auth_error === "not_authorized" && to.name !== "login") {
        return { name: "login", query: { error: "not_authorized" } };
    }
});

export default router;
