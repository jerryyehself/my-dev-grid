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
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(
                    `API Error: ${response.status} ${response.statusText} - ${errorText}`,
                );
            }
            try {
                return await response.json();
            } catch {
                return null;
            }
        } catch (error) {
            lastError = error;
            console.warn(
                `fetchAPI: Attempt ${retryCount + 1} failed:`,
                error.message,
            );
            retryCount++;
            if (retryCount < MAX_RETRIES) {
                await new Promise((resolve) =>
                    setTimeout(resolve, 300 * retryCount),
                );
            }
        }
    }
    throw new Error(
        `API failed after ${MAX_RETRIES} attempts: ${lastError?.message || "Unknown error"}`,
    );
};
