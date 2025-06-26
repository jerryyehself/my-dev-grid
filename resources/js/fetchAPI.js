/**
 * 發送 API 請求，最多重試 3 次
 * @param {string} targetURI
 * @param {object} options
 * @returns {Promise<any>}
 */
export const fetchAPI = async (targetURI, options = {}) => {
    const maxRetries = 3;
    let attempt = 0;
    let lastError;

    // set method, headers
    options.method = "GET";
    options.headers = {
        "Content-Type": "application/json",
        Accept: "application/json",
        ...(options.headers || {}),
    };

    while (attempt < maxRetries) {
        try {
            const res = await fetch(targetURI, options);

            if (!res.ok) {
                const errorText = await res.text();
                throw new Error(
                    `API Error: ${res.status} ${res.statusText} - ${errorText}`,
                );
            }

            return await res.json();
        } catch (err) {
            lastError = err;
            console.warn(`Attempt ${attempt + 1} failed:`, err.message);
            attempt++;

            // 簡單等待（可改成 exponential backoff）
            if (attempt < maxRetries) {
                await new Promise((resolve) => setTimeout(resolve, 500));
            }
        }
    }

    throw new Error(
        `API failed after ${maxRetries} attempts: ${lastError.message}`,
    );
};
