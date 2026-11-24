<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GiveCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:' . (int) config('credit.max_amount')],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_token' => ['required', 'string', 'min:32', 'max:128'],
        ];
    }
}
