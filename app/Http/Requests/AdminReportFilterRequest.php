<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class AdminReportFilterRequest extends FormRequest
{
    public const int MAX_RANGE_DAYS = 92;

    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => array_values(array_filter([
                'nullable',
                'date_format:Y-m-d',
                $this->filled('start_date') ? 'after_or_equal:start_date' : null,
            ])),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                    return;
                }

                $startDate = $this->input('start_date');
                $endDate = $this->input('end_date');
                $hasStart = is_string($startDate) && $startDate !== '';
                $hasEnd = is_string($endDate) && $endDate !== '';

                if (! $hasStart) {
                    return;
                }

                $start = Carbon::createFromFormat('Y-m-d', $startDate, 'UTC')->startOfDay();

                if (! $hasEnd) {
                    $todayUtc = Carbon::now('UTC')->startOfDay();

                    if ($start->gt($todayUtc)) {
                        $validator->errors()->add(
                            'start_date',
                            'The start date cannot be in the future when end date is omitted.',
                        );

                        return;
                    }

                    if ($start->diffInDays($todayUtc) + 1 > self::MAX_RANGE_DAYS) {
                        $validator->errors()->add(
                            'start_date',
                            'The date range from start date to today may not exceed 92 days (Pixelforge /v1/usage limit).',
                        );
                    }

                    return;
                }

                $end = Carbon::createFromFormat('Y-m-d', $endDate, 'UTC')->startOfDay();

                if ($start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
                    $validator->errors()->add(
                        'end_date',
                        'The date range may not exceed 92 days (Pixelforge /v1/usage limit).',
                    );
                }
            },
        ];
    }
}
