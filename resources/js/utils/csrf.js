/**
 * Sanctum SPA session-cookie 認證需要的 CSRF 支援。
 * `bootstrap/app.php` 的 `statefulApi()` 讓 /api/* 走 session cookie，
 * 連同 /auth/login 這種 web 路由，都靠 Laravel 內建的 XSRF-TOKEN cookie
 * + X-XSRF-TOKEN header 機制驗證，兩邊共用同一顆 cookie。
 */

export const getXsrfTokenFromCookie = () => {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : null;
};

export const ensureCsrfCookie = async () => {
    await fetch("/sanctum/csrf-cookie", { credentials: "include" });
};
