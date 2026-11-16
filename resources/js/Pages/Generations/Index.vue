<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    generations: { type: Array, required: true },
});

const flash = computed(() => usePage().props.flash?.status);

// Keep the list live while anything is still running, and stop as soon as
// everything has finished so an idle page makes no requests.
const hasActive = computed(() => props.generations.some((g) => !g.is_final));

const { start, stop } = usePoll(
    3000,
    { only: ['generations'] },
    { autoStart: false },
);

watch(hasActive, (active) => (active ? start() : stop()), { immediate: true });

const STATUS = {
    queued: { label: 'Queued', classes: 'bg-gray-100 text-gray-700' },
    processing: { label: 'Processing', classes: 'bg-blue-100 text-blue-800' },
    succeeded: { label: 'Succeeded', classes: 'bg-green-100 text-green-800' },
    failed: { label: 'Failed', classes: 'bg-red-100 text-red-800' },
};

function when(iso) {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head title="History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    History
                </h2>
                <Link
                    :href="route('generations.create')"
                    class="text-sm text-indigo-600 hover:text-indigo-800"
                >
                    New image
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                <div
                    v-if="flash"
                    class="rounded-md bg-green-50 p-4 text-sm text-green-800"
                    role="status"
                >
                    {{ flash }}
                </div>

                <div
                    v-if="generations.length === 0"
                    class="bg-white p-10 text-center shadow-sm sm:rounded-lg"
                >
                    <p class="text-gray-600">You have not generated anything yet.</p>
                    <Link
                        :href="route('generations.create')"
                        class="mt-3 inline-block text-indigo-600 hover:text-indigo-800"
                    >
                        Generate your first image
                    </Link>
                </div>

                <article
                    v-for="generation in generations"
                    :key="generation.id"
                    class="flex flex-col gap-4 bg-white p-4 shadow-sm sm:flex-row sm:rounded-lg"
                >
                    <!-- Image or state placeholder -->
                    <div
                        class="flex aspect-square w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-gray-100 sm:w-48"
                    >
                        <img
                            v-if="generation.image_url"
                            :src="generation.image_url"
                            :alt="generation.prompt"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <p
                            v-else-if="generation.image_expired"
                            class="p-3 text-center text-xs text-gray-500"
                        >
                            Image link expired
                        </p>
                        <p
                            v-else-if="generation.status === 'failed'"
                            class="p-3 text-center text-xs text-red-700"
                        >
                            No image
                        </p>
                        <div v-else class="flex flex-col items-center gap-2 text-xs text-gray-500">
                            <span
                                class="h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-gray-600"
                                aria-hidden="true"
                            />
                            {{ STATUS[generation.status].label }}…
                        </div>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="STATUS[generation.status].classes"
                            >
                                {{ STATUS[generation.status].label }}
                            </span>
                            <span class="text-xs text-gray-500">
                                {{ generation.size }} · {{ generation.quality }}
                                <template v-if="generation.reference_images.length">
                                    · {{ generation.reference_images.length }} reference
                                    {{ generation.reference_images.length === 1 ? 'image' : 'images' }}
                                </template>
                            </span>
                            <time class="ms-auto text-xs text-gray-400" :datetime="generation.created_at">
                                {{ when(generation.created_at) }}
                            </time>
                        </div>

                        <p class="mt-2 break-words text-lg font-medium leading-snug text-gray-900">
                            {{ generation.prompt }}
                        </p>

                        <p
                            v-if="generation.error_message"
                            class="mt-2 text-sm text-red-700"
                        >
                            {{ generation.error_message }}
                        </p>

                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            <dt class="text-gray-500">Estimated</dt>
                            <dd class="text-gray-800">
                                {{ generation.estimated_charge }}
                                <span class="text-xs text-gray-400">
                                    (up to {{ generation.estimated_max_charge }})
                                </span>
                            </dd>

                            <dt class="text-gray-500">Charged</dt>
                            <dd v-if="generation.charge" class="text-lg font-semibold leading-tight text-gray-900">
                                {{ generation.charge }}
                            </dd>
                            <dd v-else-if="generation.status === 'failed'" class="text-gray-800">
                                Nothing. Failed generations are free.
                            </dd>
                            <dd v-else class="text-gray-400">Calculated when finished</dd>
                        </dl>
                    </div>
                </article>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
