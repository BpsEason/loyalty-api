<?php

namespace App\Http\Requests\Api\V1\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class MixedPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:255'],
            'order_reference' => ['nullable', 'string', 'max:255'],
            'order_amount' => ['required', 'integer', 'min:0'],
            'user_coupon_id' => ['nullable', 'integer', 'exists:user_coupons,id'],
            'points_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
