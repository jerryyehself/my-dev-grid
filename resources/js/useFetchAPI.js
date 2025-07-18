import { useErrors } from "./stores/useErrorsStore";

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
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            ...(fetchOptions.headers || {}),
        },
        ...fetchOptions,
    };

    while (retryCount < MAX_RETRIES) {
        try {
            const response = await fetch(url, mergedOptions);

            if (response.status === 422) {
                const errorJson = await response.json();
                useErrors().setErrors(
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
            if (err.message.startsWith("Validation Error")) {
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
