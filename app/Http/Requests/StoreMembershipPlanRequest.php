<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMembershipPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $discountValue = ['required', 'numeric', 'decimal:0,2'];

        if ($this->input('discount_type') === 'percentage') {
            $discountValue[] = 'min:1';
            $discountValue[] = 'max:100';
        } else {
            $discountValue[] = 'gt:0';
            $discountValue[] = 'max:99999999.99';
        }

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'discount_type' => ['required', 'in:percentage,fixed'],
            'discount_value' => $discountValue,
            'players' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'expiry_date' => ['required', 'date', 'after:today'],
        ];
    }
}
