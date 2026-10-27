<script setup>
// Dahulu latar gelombang bergerak. Arah visual IGI kini tenang (tanpa gerak dekoratif terus-menerus), jadi komponen ini
// sengaja tidak menggambar apa pun. Dipertahankan agar halaman yang masih mengimpornya tidak rusak.
defineProps({ waves: { type: Boolean, default: true } });
</script>

<template>
    <span class="hidden" aria-hidden="true" />
</template>
