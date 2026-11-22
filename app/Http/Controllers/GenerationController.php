<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PixelforgeApiException;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Services\Pixelforge\GenerationService;
use App\Services\Pixelforge\PricingService;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(PricingService $pricingService): Response
    {
        $pricingConfig = null;
        $pricingError = null;

        try {
            $pricingConfig = $pricingService->getPricingConfig();
        } catch (PixelforgeApiException|ConnectionException $exception) {
            $pricingError = $exception->getMessage();
        }

        return Inertia::render('Dashboard', [
            'pricing_config' => $pricingConfig,
            'pricing_error' => $pricingError,
        ]);
    }

    public function store(StoreGenerationRequest $request, GenerationService $generationService): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $generation = $generationService->initiate(
                user: $request->user(),
                prompt: (string) $validated['prompt'],
                size: ImageSize::from((string) $validated['size']),
                quality: ImageQuality::from((string) $validated['quality']),
                referenceImages: $validated['reference_images'] ?? [],
            );
        } catch (InsufficientCreditsException $exception) {
            $required = Money::formatDisplay($exception->requiredNano);
            $available = Money::formatDisplay($exception->availableNano);

            throw ValidationException::withMessages([
                'credits' => "Insufficient credits. Maximum estimated cost is {$required}, available balance is {$available}.",
            ]);
        } catch (PixelforgeApiException|ConnectionException $exception) {
            throw ValidationException::withMessages([
                'pricing' => "Unable to fetch current Pixelforge pricing: {$exception->getMessage()}",
            ]);
        }

        [$flashType, $flashMessage] = $generation->status === GenerationStatus::Failed
            ? ['error', $generation->error_message ?? 'Generation request was rejected.']
            : ['success', 'Generation submitted successfully.'];

        return to_route('dashboard')
            ->with($flashType, $flashMessage)
            ->with('current_generation', GenerationResource::make($generation)->resolve());
    }
}
