import { ref } from 'vue';

const KEY = 'igi:ukuran-teks';
const big = ref(false);

function apply() {
    document.documentElement.dataset.text = big.value ? 'besar' : 'normal';
}

/**
 * Pilihan ukuran teks (Normal atau Besar) untuk pembaca yang lebih nyaman dengan huruf besar. Disimpan di perangkat;
 * penyimpanan dibungkus try/catch supaya tetap jalan di mode pribadi.
 */
export function useTextSize() {
    function load() {
        try {
            big.value = localStorage.getItem(KEY) === 'besar';
        } catch {
            // tanpa penyimpanan: tetap normal
        }
        apply();
    }

    function toggle() {
        big.value = !big.value;
        apply();

        try {
            localStorage.setItem(KEY, big.value ? 'besar' : 'normal');
        } catch {
            // pilihan hanya berlaku selama halaman terbuka
        }
    }

    return { big, load, toggle };
}
