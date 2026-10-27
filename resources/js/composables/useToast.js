import { ref } from 'vue';

const toasts = ref([]);
let counter = 0;

export function useToast() {
    function dismiss(id) {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }

    /** type: success | error | info | alert. Galat bertahan lebih lama, peringatan gempa paling lama supaya sempat dibaca. */
    function toast(message, type = 'success') {
        const id = ++counter;
        toasts.value = [...toasts.value, { id, message, type }];
        setTimeout(() => dismiss(id), type === 'alert' ? 20000 : type === 'error' ? 9000 : 5000);
    }

    return { toasts, toast, dismiss };
}
