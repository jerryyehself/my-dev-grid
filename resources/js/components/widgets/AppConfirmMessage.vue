<template>
    <div
        v-if="confirm.isVisible"
        class="fixed inset-0 flex items-center justify-center bg-black/30 backdrop-blur-sm"
    >
        <div
            class="bg-white p-4 rounded-md shadow-md h-50 w-75 border-stone-500 border-1 flex flex-col items-center justify-around gap-4"
        >
            <div class="text-lg flex items-center justify-center gap-2">
                <ExclamationTriangleIcon class="w-6 h-6 text-stone-700 mt-1" />
                <span>{{ confirm.message }}</span>
            </div>
            <div class="flex gap-4">
                <button
                    class="bg-stone-600 text-white px-4 py-2 rounded cursor-pointer"
                    @click="confirm.confirm"
                >
                    確定
                </button>
                <button
                    class="bg-stone-400 text-white px-4 py-2 rounded cursor-pointer"
                    @click="confirm.cancel"
                >
                    取消
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useConfirmStore } from "@/stores/useConfirmStore";
import { ExclamationTriangleIcon } from "@heroicons/vue/24/solid";
const confirm = useConfirmStore();
</script>
