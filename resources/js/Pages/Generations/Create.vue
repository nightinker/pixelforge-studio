<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    sizes: { type: Array, required: true },
    qualities: { type: Array, required: true },
    markupPercent: { type: Number, required: true },
});

const MAX_PROMPT = 4000;
const MAX_REFERENCES = 4;

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

// Blank reference rows are ignored, both for the price and when submitting.
const filledReferences = computed(() =>
    form.reference_images.map((url) => url.trim()).filter(Boolean),
);

const promptLength = computed(() => [...form.prompt].length);

// ---- live price -----------------------------------------------------------
// The price always comes from the server, so what is shown is exactly the
// calculation that will be stored. Requests are debounced, and a response
// that arrives after a newer request was sent is thrown away.
const estimate = ref(null);
const estimateError = ref(null);
const estimating = ref(false);
let timer = null;
let latestRequest = 0;

async function loadEstimate() {
    const request = ++latestRequest;
    estimating.value = true;

    try {
        const { data } = await axios.get(route('generations.estimate'), {
            params: {
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_count: filledReferences.value.length,
            },
        });

        if (request !== latestRequest) return;
        estimate.value = data;
        estimateError.value = null;
    } catch (error) {
        if (request !== latestRequest) return;
        estimate.value = null;
        estimateError.value =
            error.response?.data?.message ?? 'The price could not be loaded.';
    } finally {
        if (request === latestRequest) estimating.value = false;
    }
}

watch(
    () => [form.prompt, form.size, form.quality, filledReferences.value.length],
    () => {
        clearTimeout(timer);
        timer = setTimeout(loadEstimate, 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));

// ---- reference images -----------------------------------------------------
function addReference() {
    if (form.reference_images.length < MAX_REFERENCES) {
        form.reference_images.push('');
    }
}

function removeReference(index) {
    form.reference_images.splice(index, 1);
}

// ---- submit ---------------------------------------------------------------
const canSubmit = computed(
    () => form.prompt.trim() !== '' && promptLength.value <= MAX_PROMPT && !form.processing,
);

function submit() {
    form.transform((data) => ({
        ...data,
        reference_images: filledReferences.value,
    })).post(route('generations.store'));
}
</script>

<template>
    <Head title="Generate" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Generate an image
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form
                    class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg"
                    @submit.prevent="submit"
                >
                    <div
                        v-if="form.errors.form"
                        class="rounded-md bg-red-50 p-4 text-sm text-red-800"
                        role="alert"
                    >
                        {{ form.errors.form }}
                    </div>

                    <div>
                        <InputLabel for="prompt" value="Prompt" />
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="4"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            autofocus
                        />
                        <div class="mt-1 flex justify-between text-xs text-gray-500">
                            <InputError :message="form.errors.prompt" />
                            <span
                                class="ms-auto"
                                :class="{ 'text-red-600': promptLength > MAX_PROMPT }"
                            >
                                {{ promptLength }} / {{ MAX_PROMPT }}
                            </span>
                        </div>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <InputLabel for="size" value="Size" />
                            <select
                                id="size"
                                v-model="form.size"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="size in props.sizes" :key="size" :value="size">
                                    {{ size }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.size" />
                        </div>

                        <div>
                            <InputLabel for="quality" value="Quality" />
                            <select
                                id="quality"
                                v-model="form.quality"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm capitalize focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="quality in props.qualities" :key="quality" :value="quality">
                                    {{ quality }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.quality" />
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <InputLabel value="Reference images (optional)" />
                            <button
                                v-if="form.reference_images.length < MAX_REFERENCES"
                                type="button"
                                class="text-sm text-indigo-600 hover:text-indigo-800"
                                @click="addReference"
                            >
                                + Add image URL
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            Up to {{ MAX_REFERENCES }} http(s) image URLs to guide the style. Each one adds to the price.
                        </p>

                        <div
                            v-for="(url, index) in form.reference_images"
                            :key="index"
                            class="mt-2"
                        >
                            <div class="flex gap-2">
                                <TextInput
                                    v-model="form.reference_images[index]"
                                    type="url"
                                    class="block w-full"
                                    placeholder="https://example.com/style.png"
                                    :aria-label="`Reference image ${index + 1}`"
                                />
                                <button
                                    type="button"
                                    class="rounded-md px-3 text-sm text-gray-500 hover:text-red-600"
                                    :aria-label="`Remove reference image ${index + 1}`"
                                    @click="removeReference(index)"
                                >
                                    Remove
                                </button>
                            </div>
                            <InputError
                                class="mt-1"
                                :message="form.errors[`reference_images.${index}`]"
                            />
                        </div>
                        <InputError class="mt-2" :message="form.errors.reference_images" />
                    </div>

                    <div class="rounded-md bg-gray-50 p-4" aria-live="polite">
                        <p class="text-sm font-medium text-gray-700">Estimated price</p>

                        <p v-if="estimateError" class="mt-1 text-sm text-red-700">
                            {{ estimateError }}
                        </p>
                        <template v-else-if="estimate">
                            <p
                                class="mt-1 text-2xl font-semibold text-gray-900"
                                :class="{ 'opacity-50': estimating }"
                            >
                                about {{ estimate.expected }}
                            </p>
                            <p class="mt-1 text-sm text-gray-600">
                                Between {{ estimate.min }} and {{ estimate.max }}. You will never pay
                                more than {{ estimate.max }}.
                            </p>
                        </template>
                        <p v-else class="mt-1 text-sm text-gray-500">Calculating…</p>

                        <p class="mt-2 text-xs text-gray-500">
                            Includes a {{ props.markupPercent }}% service fee. The exact price is only known
                            once the image is finished, and it is shown in your history. If the
                            generation fails you pay nothing.
                        </p>
                    </div>

                    <div class="flex justify-end">
                        <PrimaryButton :disabled="!canSubmit" :class="{ 'opacity-25': !canSubmit }">
                            {{ form.processing ? 'Sending…' : 'Generate' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
