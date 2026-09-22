<?php

namespace App\Http\Requests\Api\V1\Reward;

use Illuminate\Foundation\Http\FormRequest;

class GrantRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'campaign_reward_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'campaign_reward_id.required' => 'The campaign reward ID is required.',
            'campaign_reward_id.integer' => 'The campaign reward ID must be an integer.',
            'campaign_reward_id.min' => 'The campaign reward ID must be a positive integer.',
        ];
    }
}
