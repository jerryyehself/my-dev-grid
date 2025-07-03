<template>
    <svg
        :width="width"
        :height="height"
        :viewBox="arrowSetting[direction].viewBox"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        xmlns="http://www.w3.org/2000/svg"
    >
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            :d="arrowSetting[direction].path1D"
        />
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            :d="arrowSetting[direction].path2D"
        />
    </svg>
</template>

<script setup>
const arrowSetting = {
    right: {
        viewBox: "0 0 60 24",
        path1D: "M4 12h42", // 主幹線拉長
        path2D: "M40 6l8 6-8 6", // 箭頭
    },
    left: {
        viewBox: "0 0 60 24",
        path1D: "M56 12H14", // 主幹線
        path2D: "M22 6l-8 6 8 6", // 箭頭
    },
    both: {
        viewBox: "0 0 60 24",
        path1D: "M8 12h44", // 中央主幹
        path2D: "M14 6l-8 6 8 6 M46 6l8 6-8 6", // 左右箭頭
    },
};

defineProps({
    width: { type: [String, Number], default: 60 },
    height: { type: [String, Number], default: 24 },
    direction: {
        type: String,
        default: "right",
    },
});
</script>
