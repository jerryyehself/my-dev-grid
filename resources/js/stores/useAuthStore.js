import { defineStore } from "pinia";
import { ref } from "vue";
import { ensureCsrfCookie, getXsrfTokenFromCookie } from "../utils/csrf";

export const useAuthStore = defineStore("auth", () => {
    const user = ref(null);
    const checked = ref(false);

    /**
     * 開機檢查登入狀態。刻意用原生 fetch、不走 fetchAPI——
     * 未登入時這裡的 401 是正常狀態，不該觸發 fetchAPI 的全域
     * 401 → 導去登入頁邏輯（開機就在登入頁重導會變迴圈）。
     */
    async function checkAuth() {
        try {
            const response = await fetch("/api/user", {
                credentials: "include",
                headers: { Accept: "application/json" },
            });
            user.value = response.ok ? await response.json() : null;
        } catch {
            user.value = null;
        } finally {
            checked.value = true;
        }
    }

    /**
     * @returns {Promise<{ok: true}|{ok: false, message: string}>}
     */
    async function login(email, password) {
        await ensureCsrfCookie();
        const response = await fetch("/auth/login", {
            method: "POST",
            credentials: "include",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": getXsrfTokenFromCookie(),
            },
            body: JSON.stringify({ email, password }),
        });

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            const message =
                body.message ||
                Object.values(body.errors || {})[0]?.[0] ||
                "登入失敗，請確認帳號密碼";
            return { ok: false, message };
        }

        user.value = await response.json();
        return { ok: true };
    }

    async function logout() {
        await fetch("/auth/logout", {
            method: "POST",
            credentials: "include",
            headers: {
                Accept: "application/json",
                "X-XSRF-TOKEN": getXsrfTokenFromCookie(),
            },
        });
        user.value = null;
    }

    return { user, checked, checkAuth, login, logout };
});
