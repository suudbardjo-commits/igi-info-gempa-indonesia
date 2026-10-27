<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    /** "center" untuk formulir pendek, "side" untuk panel rincian di kanan. */
    variant: { type: String, default: 'center' },
});

const emit = defineEmits(['close']);

const panel = ref(null);
let previouslyFocused = null;

function onKey(event) {
    if (event.key === 'Escape') emit('close');
}

watch(
    () => props.open,
    async (open) => {
        if (open) {
            previouslyFocused = document.activeElement;
            document.addEventListener('keydown', onKey);
            await nextTick();
            panel.value?.querySelector('input, select, textarea, button:not([data-dialog-close])')?.focus();
        } else {
            document.removeEventListener('keydown', onKey);
            previouslyFocused?.focus?.();
        }
    },
);

onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-100"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-50 flex bg-ink/45" :class="variant === 'side' ? 'justify-end' : 'items-center justify-center p-4'" @mousedown.self="emit('close')">
                <section
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="title"
                    class="flex flex-col bg-surface shadow-xl"
                    :class="variant === 'side' ? 'h-full w-full max-w-[30rem]' : 'max-h-[calc(100dvh-2rem)] w-full max-w-lg rounded-xl'"
                >
                    <header class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
                        <h2 class="text-base font-semibold leading-snug">{{ title }}</h2>
                        <button
                            type="button"
                            data-dialog-close
                            class="-mr-1 rounded px-2 py-0.5 text-xl leading-none text-muted hover:text-ink"
                            aria-label="Tutup"
                            @click="emit('close')"
                        >
                            &times;
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <slot />
                    </div>

                    <footer v-if="$slots.footer" class="flex flex-wrap items-center justify-end gap-2 border-t border-line px-5 py-3">
                        <slot name="footer" />
                    </footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>
