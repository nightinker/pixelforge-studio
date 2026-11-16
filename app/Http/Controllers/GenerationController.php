<?php

namespace App\Http\Controllers;

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

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest()->limit(50)->get();

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($generations)->resolve(),
        ]);
    }
}
