<template>
    <header
        class="h-18 bg-gradient-to-b text-stone-700 grid grid-cols-4 items-center py-2 px-20 box-border shadow-stone-500 shadow-sm relative z-10"
    >
        <h1
            class="col-span-1 xl:text-4xl md:text-2xl font-bold relative inline-block font-serif"
        >
            <a href="/" class="block">My Dev Grid</a>
        </h1>
        <nav
            class="col-span-2 flex space-x-20 text-stone-700 font-medium justify-center xl:text-xl md:text-md"
        >
            <RouterLink
                v-for="(page, key) in pages"
                :key="key"
                :to="page.to"
                :class="[
                    'relative inline-block px-1 after:absolute after:left-0 after:bottom-0 after:h-[2px] after:w-0 after:bg-white after:transition-all after:duration-300',
                    {
                        'text-stone-700 after:w-full': $route.path === page.to,
                        'hover:after:w-full hover:text-stone-700':
                            $route.path !== page.to,
                    },
                ]"
            >
                {{ page.label }}
            </RouterLink>
        </nav>
        <div class="col-span-1 flex justify-end text-sm text-stone-700">
            <RouterLink v-if="!authStore.user" to="/login">登入</RouterLink>
            <div v-else class="flex items-center gap-3">
                <span class="truncate max-w-[10rem]">
                    {{ authStore.user.name || authStore.user.email }}
                </span>
                <button type="button" @click="authStore.logout()">
                    登出
                </button>
            </div>
        </div>
    </header>
    <main class="relative flex-1 min-h-0 box-border overflow-hidden h-full">
        <RouterView />
        <AppConfirmMessage />
    </main>
    <footer></footer>
</template>

<script setup>
import { ref, onMounted } from "vue";
import AppConfirmMessage from "@/components/widgets/AppConfirmMessage.vue";
import { useAuthStore } from "@/stores/useAuthStore";

const showWarning = ref(true);
const authStore = useAuthStore();
onMounted(() => authStore.checkAuth());

const pages = {
    home: {
        to: "/",
        label: "Home",
    },
    article: {
        to: "/triple-control",
        label: "Admin",
    },
    about: {
        to: "/projects",
        label: "Projects",
    },
};
</script>
