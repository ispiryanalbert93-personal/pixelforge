<?php

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $referenceImages = $this->input('reference_images');

        if (! is_array($referenceImages)) {
            return;
        }

        $filtered = array_values(array_filter(
            $referenceImages,
            static fn (mixed $value): bool => $value !== null && (! is_string($value) || trim($value) !== ''),
        ));

        $normalized = array_map(
            static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $filtered,
        );

        $this->merge([
            'reference_images' => $normalized,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:'.PricingCalculator::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }
}
