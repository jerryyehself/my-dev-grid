<template>
    <div class="h-full px-20 pb-16 pt-8 flex justify-center items-center">
        <div
            class="grid shadow-xl border border-stone-200 lg:aspect-86/54 lg:grid-rows-1 grid-rows-[1.5fr_1fr] grid-cols-1 lg:grid-cols-[1fr_2fr] aspect-62/86 h-full"
        >
            <div
                class="flex flex-col px-5 lg:py-10 bg-stone-300 items-center justify-center lg:gap-6 gap-3"
            >
                <img
                    src="https://avatars.githubusercontent.com/u/87906523?v=4"
                    alt=""
                    class="rounded-full aspect-square lg:h-50 h-40"
                />

                <h2 class="lg:text-2xl text-xl font-bold text-center">
                    Jerry Yeh
                </h2>

                <ul
                    class="text-stone-600 text-left gap-1 flex flex-col text-sm lg:text-base"
                >
                    <li>
                        <a
                            href="mailto:jerry40522@gmail.com"
                            class="inline-flex items-center gap-1"
                        >
                            <EnvelopeIcon class="h-[1em] w-[1em] shrink-0" />
                            jerry40522@gmail.com
                        </a>
                    </li>
                    <li>
                        <a href="https://github.com/jerryyehself"
                            >jerryyehself</a
                        >
                    </li>
                    <li>
                        <a href="https://www.linkedin.com/in/jerryyeh/"
                            >LinkedIn</a
                        >
                    </li>
                </ul>
            </div>

            <div></div>
        </div>
    </div>
</template>

<script setup>
import { EnvelopeIcon } from "@heroicons/vue/16/solid";
</script>
