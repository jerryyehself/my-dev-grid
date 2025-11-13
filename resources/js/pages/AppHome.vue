<template>
    <div class="w-full h-full flex flex-col">
        <!-- Hero / Menu -->
        <div class="h-full w-full overflow-y-auto scroll-snap-y snap-mandatory">
            <div class="h-full grid grid-cols-2">
                <div
                    class="flex flex-col items-center justify-center bg-clip-text text-transparent bg-cover gap-10"
                    :style="{ backgroundImage: `url(${landingBg})` }"
                >
                    <a
                        v-for="slogen in slogens"
                        :key="slogen"
                        :href="`#section-${slogen}`"
                        class="text-6xl font-black cursor-pointer w-fit hover:text-7xl transition-all duration-500"
                    >
                        {{ slogen }}
                    </a>
                </div>
            </div>

            <!-- 滾動容器 -->
            <section
                v-for="slogen in slogens"
                :key="logen"
                :id="`section-${slogen}`"
                class="h-screen w-full flex items-center justify-center bg-stone-900 text-white text-6xl snap-start"
            >
                {{ slogen }}
            </section>
        </div>
    </div>
</template>
<script setup>
import landingBg from "@/assets/images/landing-bg.png";

let slogens = ["Practice", "Thinking", "Creative"];
</script>
