<?php

namespace App\Http\Requests\Api\V1\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class CouponClaimRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'exists:coupon_templates,code'],
        ];
    }
}
