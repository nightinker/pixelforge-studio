<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Pixelforge\PixelforgeException;
use App\Pricing\Nanodollars;
use App\Services\GenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly GenerationService $generations) {}

    public function create(): Response
    {
        return Inertia::render('Generations/Create', [
            'sizes' => Generation::SIZES,
            'qualities' => Generation::QUALITIES,
            'markupPercent' => (int) config('services.pixelforge.markup_percent'),
        ]);
    }

    /** Live price shown while the user types. Same code path as the stored estimate. */
    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', Rule::in(Generation::SIZES)],
            'quality' => ['required', Rule::in(Generation::QUALITIES)],
            'reference_count' => ['nullable', 'integer', 'min:0', 'max:4'],
        ]);

        try {
            $estimate = $this->generations->estimate(
                $data['prompt'] ?? '',
                $data['size'],
                $data['quality'],
                (int) ($data['reference_count'] ?? 0),
            );
        } catch (PixelforgeException $e) {
            return response()->json(['message' => $e->userMessage()], 503);
        }

        return response()->json([
            'expected' => Nanodollars::format($estimate->expected->charge),
            'min' => Nanodollars::format($estimate->min->charge),
            'max' => Nanodollars::format($estimate->max->charge),
        ]);
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        try {
            $this->generations->create($request->user(), $request->validated());
        } catch (PixelforgeException $e) {
            // Could not even fetch prices, so nothing was recorded.
            return back()->withInput()->withErrors(['form' => $e->userMessage()]);
        }

        return redirect()->route('generations.index')
            ->with('status', 'Your image is on its way. You are only charged if it succeeds.');
    }

    /**
     * Try a failed generation again.
     *
     * This creates a NEW generation (new id, so a new Idempotency-Key) with the same
     * inputs. The failed one is final and stays in the history as it was; reusing its
     * key could simply hand back the same failure.
     */
    public function retry(Request $request, string $generation): RedirectResponse
    {
        // Scoped to the user's own generations: someone else's id is a plain 404.
        $original = $request->user()->generations()->findOrFail($generation);

        abort_unless(
            $original->status === GenerationStatus::Failed,
            409,
            'Only a failed generation can be retried.',
        );

        try {
            $this->generations->create($request->user(), [
                'prompt' => $original->prompt,
                'size' => $original->size,
                'quality' => $original->quality,
                'reference_images' => $original->reference_images ?? [],
            ]);
        } catch (PixelforgeException $e) {
            return back()->withErrors(['retry' => $e->userMessage()]);
        }

        return redirect()->route('generations.index')
            ->with('status', 'Trying again. You are only charged if it succeeds.');
    }

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest()->limit(50)->get();

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($generations)->resolve(),
        ]);
    }
}
