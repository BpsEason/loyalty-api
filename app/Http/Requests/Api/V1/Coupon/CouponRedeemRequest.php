<?php

namespace App\Http\Requests\Api\V1\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class CouponRedeemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:255'],
            'order_reference' => ['nullable', 'string', 'max:255'],
            'order_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
