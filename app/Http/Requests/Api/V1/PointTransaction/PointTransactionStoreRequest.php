<?php

namespace App\Http\Requests\Api\V1\PointTransaction;

use Illuminate\Foundation\Http\FormRequest;

class PointTransactionStoreRequest extends FormRequest
{
    public function rules(): array
    {
        $isRedeemEndpoint = str_contains($this->path(), 'points/redeem');

        return [
            'type' => $isRedeemEndpoint ? ['nullable', 'string', 'in:earn,redeem,adjust,refund,expire'] : ['required', 'string', 'in:earn,redeem,adjust,refund,expire'],
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
