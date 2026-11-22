<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TopUpWalletRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_usd' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0.50',
                'max:1000',
            ],
        ];
    }
}
