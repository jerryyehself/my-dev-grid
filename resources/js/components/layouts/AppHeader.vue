<template>
    <header>
        <h1
            class="col-span-1 xl:text-4xl md:text-2xl font-bold relative inline-block font-serif"
        >
            <a href="/" class="block p-3">My Dev Grid</a>
        </h1>
        <nav
            class="col-span-2 flex space-x-20 text-stone-300 font-medium justify-center xl:text-xl md:text-md"
        >
            <a
                v-for="(page, index) in pages"
                :key="index"
                :href="page.href"
                class="relative inline-block px-1 after:content-[''] after:absolute after:left-0 after:bottom-0 after:h-[2px] after:w-0 after:bg-white after:transition-all after:duration-300 hover:after:w-full hover:text-white"
            >
                {{ page.label }}
            </a>
        </nav>
    </header>
</template>

<script setup>
const pages = {
    home: {
        href: "",
        label: "首頁",
    },
    article: {
        href: "",
        label: "文章",
    },
    about: {
        href: "",
        label: "關於我",
    },
};
</script>
