<script setup>
const steps = [
    { color: '#e9b949', label: 'di bawah M 4,5' },
    { color: '#f08a24', label: 'M 4,5 sampai 5,4' },
    { color: '#e5412d', label: 'M 5,5 sampai 6,4' },
    { color: '#a3123a', label: 'M 6,5 ke atas' },
];
</script>

<template>
    <div class="hidden rounded-lg border border-line bg-surface/95 p-3 text-xs shadow-sm backdrop-blur md:block">
        <p class="font-semibold">Keterangan peta</p>
        <ul class="mt-2 space-y-1.5">
            <li v-for="s in steps" :key="s.color" class="flex items-center gap-2">
                <span class="size-3 rounded-full ring-2 ring-white" :style="{ background: s.color }" />
                {{ s.label }}
            </li>
            <li class="flex items-center gap-2 pt-1">
                <span class="size-3 rounded-[3px] border-2 border-white bg-camp ring-1 ring-ink/30" />
                Posko pengungsian
            </li>
            <li class="flex items-center gap-2">
                <span class="size-3 rounded-full border border-quake-3 bg-quake-3/10" />
                Perkiraan radius dampak
            </li>
        </ul>
    </div>
</template>
