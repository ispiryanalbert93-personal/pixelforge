<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PixelforgeApiException;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\Pixelforge\ErrorFormatter;
use App\Services\Pixelforge\GenerationService;
use App\Services\Pixelforge\PricingService;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class GenerationController extends Controller
{
    public function index(Request $request, PricingService $pricingService): Response
    {
        $pricingConfig = null;
        $pricingError = null;

        try {
            $pricingConfig = $pricingService->getPricingConfig();
        } catch (PixelforgeApiException|ConnectionException $exception) {
            Log::warning('Failed to load Pixelforge pricing configuration.', [
                'message' => $exception->getMessage(),
            ]);
            $pricingError = ErrorFormatter::forPricing($exception);
        }

        $activeGeneration = $request->user()
            ->generations()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Dashboard', [
            'pricing_config' => $pricingConfig,
            'pricing_error' => $pricingError,
            'active_generation' => $activeGeneration
                ? GenerationResource::make($activeGeneration)->resolve($request)
                : null,
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
            Log::warning('Failed to initiate generation due to Pixelforge pricing error.', [
                'message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'pricing' => ErrorFormatter::forPricing($exception),
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'prompt' => $exception->getMessage(),
            ]);
        }

        [$flashType, $flashMessage] = $generation->status === GenerationStatus::Failed
            ? ['error', $generation->error_message ?? 'Generation request was rejected.']
            : ['success', 'Generation submitted successfully.'];

        return to_route('dashboard')
            ->with($flashType, $flashMessage)
            ->with('current_generation', GenerationResource::make($generation)->resolve($request));
    }

    public function sync(Request $request, Generation $generation, GenerationService $generationService): RedirectResponse
    {
        abort_if($generation->user_id !== $request->user()->id, 403);

        try {
            $updated = $generationService->syncFromProvider($generation);
        } catch (PixelforgeApiException|ConnectionException $exception) {
            Log::warning('Manual generation sync failed.', [
                'generation_id' => $generation->id,
                'message' => $exception->getMessage(),
            ]);

            return back(fallback: route('generations.history'))
                ->with('error', 'Unable to sync generation status from Pixelforge right now. Please try again shortly.');
        }

        return back(fallback: route('generations.history'))
            ->with('success', "Generation status synced ({$updated->status->value}).")
            ->with('current_generation', GenerationResource::make($updated)->resolve($request));
    }
}
