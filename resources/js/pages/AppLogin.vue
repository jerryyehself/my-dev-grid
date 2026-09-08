<template>
    <div
        class="min-h-full w-full flex items-stretch"
        style="min-height: 640px"
    >
        <div
            class="w-2/5 bg-stone-800 text-stone-200 flex flex-col justify-center px-10 py-10 gap-3"
        >
            <span
                class="text-xs tracking-widest uppercase text-stone-400"
            >
                my-dev-grid
            </span>
            <h1 class="text-xl font-bold text-white m-0">Triple 後台</h1>
            <p class="text-sm leading-relaxed text-stone-400 m-0">
                知識圖譜的內容管理入口。<br />
                登入後可編輯 Scope、Relation、Blog 與專案資料。
            </p>
        </div>

        <div class="flex-1 flex items-center justify-center p-6">
            <div class="w-80 flex flex-col gap-5">
                <span class="text-sm text-stone-500">登入以繼續</span>

                <p
                    v-if="authErrorMessage"
                    class="text-xs text-red-600 -mt-3"
                >
                    {{ authErrorMessage }}
                </p>

                <div class="flex flex-col gap-2.5">
                    <a
                        href="/auth/google/redirect"
                        class="oauth-btn bg-white border border-stone-400 text-stone-800"
                    >
                        <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                            <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.9c1.7-1.57 2.7-3.88 2.7-6.62Z"/>
                            <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.9-2.26c-.8.54-1.84.86-3.06.86-2.35 0-4.34-1.59-5.05-3.72H.98v2.33A9 9 0 0 0 9 18Z"/>
                            <path fill="#FBBC05" d="M3.95 10.7A5.4 5.4 0 0 1 3.66 9c0-.59.1-1.16.29-1.7V4.97H.98A9 9 0 0 0 0 9c0 1.45.35 2.83.98 4.03l2.97-2.33Z"/>
                            <path fill="#EA4335" d="M9 3.58c1.32 0 2.51.46 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .98 4.97l2.97 2.33C4.66 5.17 6.65 3.58 9 3.58Z"/>
                        </svg>
                        <span>使用 Google 繼續</span>
                    </a>

                    <a
                        href="/auth/line/redirect"
                        class="oauth-btn text-white"
                        style="background: #06c755"
                    >
                        <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                            <rect width="18" height="18" rx="4" fill="#06C755" />
                            <path
                                fill="#ffffff"
                                d="M14.5 8.3c0-2.36-2.36-4.28-5.27-4.28S3.96 5.94 3.96 8.3c0 2.12 1.87 3.9 4.4 4.23.17.04.4.11.46.26.05.13.03.34.02.47l-.07.44c-.02.13-.1.5.44.27.53-.22 2.88-1.7 3.93-2.9.72-.8 1.36-1.72 1.36-2.77Z"
                            />
                        </svg>
                        <span>使用 LINE 繼續</span>
                    </a>
                </div>

                <div class="flex items-center gap-2.5">
                    <div class="flex-1 h-px bg-stone-400"></div>
                    <span class="text-xs text-stone-500">或</span>
                    <div class="flex-1 h-px bg-stone-400"></div>
                </div>

                <form
                    class="flex flex-col gap-2"
                    @submit.prevent="onSubmit"
                >
                    <div class="field-wrap">
                        <input
                            v-model="email"
                            type="email"
                            placeholder="Email"
                            autocomplete="username"
                        />
                    </div>
                    <div class="field-wrap">
                        <input
                            v-model="password"
                            type="password"
                            placeholder="密碼"
                            autocomplete="current-password"
                        />
                    </div>

                    <p v-if="formError" class="text-xs text-red-600 m-0">
                        {{ formError }}
                    </p>

                    <button
                        type="submit"
                        class="oauth-btn text-white mt-1 disabled:opacity-60"
                        style="background: #57534e"
                        :disabled="submitting"
                    >
                        {{ submitting ? "登入中…" : "登入" }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "@/stores/useAuthStore";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const email = ref("");
const password = ref("");
const formError = ref("");
const submitting = ref(false);

const authErrorMessage =
    route.query.error === "not_authorized"
        ? "沒有對應的帳號授權，請聯絡管理員"
        : "";

const onSubmit = async () => {
    submitting.value = true;
    formError.value = "";
    const result = await authStore.login(email.value, password.value);
    submitting.value = false;

    if (!result.ok) {
        formError.value = result.message;
        return;
    }

    router.push(route.query.redirect || "/");
};
</script>

<style scoped>
.oauth-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 8px 16px;
    border-radius: 2px;
    font-size: 14px;
    font-weight: 500;
    letter-spacing: 0.025em;
    cursor: pointer;
    transition: transform 0.2s;
}
.oauth-btn:hover {
    transform: scale(1.02);
}
.field-wrap {
    border-radius: 2px;
    background: #ffffff;
    border: 1px solid #a8a29e;
}
.field-wrap input {
    width: 100%;
    padding: 6px 8px;
    border: none;
    background: transparent;
    font-size: 14px;
    font-family: inherit;
    color: #292524;
    outline: none;
}
.field-wrap input::placeholder {
    color: #a8a29e;
}
</style>
