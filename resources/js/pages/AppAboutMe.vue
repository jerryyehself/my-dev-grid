<template>
    <div class="h-full px-20 pb-16 pt-8">
        <div
            class="grid grid-cols-[1fr_3fr] h-full shadow-xl border-1 border-stone-200"
        >
            <div
                class="col-span-1 flex flex-col px-5 py-10 bg-stone-300 items-center gap-6"
            >
                <img
                    src="https://avatars.githubusercontent.com/u/87906523?v=4"
                    alt=""
                    class="rounded-full h-50 w-50"
                />
                <h2 class="text-2xl font-bold text-center">Jerry Yeh</h2>

                <ul class="text-stone-600 text-left gap-1 flex flex-col flex-1">
                    <li>
                        <a
                            href="mailto:jerry40522@gmail.com"
                            class="inline-flex items-center gap-1"
                        >
                            <EnvelopeIcon
                                class="h-[1em] w-[1em] flex-shrink-0"
                            />
                            jerry40522@gmail.com</a
                        >
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
            <!-- <div class="w-1 mx-4 my-auto h-1/2 bg-stone-400"></div> -->
            <div></div>
        </div>
    </div>
</template>

<script setup>
import { EnvelopeIcon } from "@heroicons/vue/16/solid";
</script>
