import { useErrorsStore } from "../stores/useErrorsStore";
import { useAuthStore } from "../stores/useAuthStore";
import router from "../router";
import { getXsrfTokenFromCookie } from "./csrf";

/**
 * 發送 API 請求，最多重試 3 次
 * @param {string} targetURI
 * @param {object} options
 * @returns {Promise<any>}
 */
export const fetchAPI = async (url, fetchOptions = {}) => {
    const MAX_RETRIES = 3;
    let retryCount = 0;
    let lastError = null;

    const mergedOptions = {
        method: fetchOptions.method || "GET",
        credentials: "include",
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-XSRF-TOKEN": getXsrfTokenFromCookie(),
            ...(fetchOptions.headers || {}),
        },
        ...fetchOptions,
    };

    while (retryCount < MAX_RETRIES) {
        try {
            const response = await fetch(url, mergedOptions);

            if (response.status === 401) {
                // 寫入端點現在都要求登入（D-34/PR #32）；session 過期或根本
                // 沒登入都會落到這裡，統一導去登入頁，不重試。
                useAuthStore().user = null;
                router.push({
                    name: "login",
                    query: { redirect: router.currentRoute.value.fullPath },
                });
                throw new Error(`Unauthorized`);
            }

            if (response.status === 422) {
                const errorJson = await response.json();
                useErrorsStore().setErrors(
                    errorJson.errors || errorJson.message || {},
                );

                throw new Error(`Validation Error`);
            }

            if (!response.ok) {
                throw new Error(
                    `API Error ${response.status}: ${await response.text()}`,
                );
            }

            try {
                const body = await response.json();
                return response.status === 200
                    ? body
                    : { body: body, status: response.status };
            } catch {
                return null;
            }
        } catch (err) {
            if (
                err.message.startsWith("Validation Error") ||
                err.message.startsWith("Unauthorized")
            ) {
                throw err;
            }

            // 其餘錯誤 -> 重試
            lastError = err;
            retryCount++;
            if (retryCount >= MAX_RETRIES) break;
            await new Promise((r) => setTimeout(r, 300 * retryCount));
        }
    }
    throw new Error(
        `API failed after ${MAX_RETRIES} attempts: ${lastError?.message ?? "Unknown"}`,
    );
};
