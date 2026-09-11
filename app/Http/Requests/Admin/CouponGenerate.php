<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CouponGenerate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|integer|max:500',
            'name' => 'required',
            'type' => 'required|in:1,2',
            'value' => 'required|integer',
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer',
            'limit_use' => 'nullable|integer',
            'limit_use_with_user' => 'nullable|integer',
            'limit_plan_ids' => 'nullable|array',
            'limit_period' => 'nullable|array',
            'code' => ''
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => __('The generation quantity must be a number'),
            'generate_count.max' => __('The generation quantity cannot exceed 500'),
            'name.required' => __('The name cannot be empty'),
            'type.required' => __('The type cannot be empty'),
            'type.in' => __('The type is invalid'),
            'value.required' => __('The amount or ratio cannot be empty'),
            'value.integer' => __('The amount or ratio is invalid'),
            'started_at.required' => __('The start time cannot be empty'),
            'started_at.integer' => __('The start time is invalid'),
            'ended_at.required' => __('The end time cannot be empty'),
            'ended_at.integer' => __('The end time is invalid'),
            'limit_use.integer' => __('The maximum number of uses is invalid'),
            'limit_use_with_user.integer' => __('The per-user usage limit is invalid'),
            'limit_plan_ids.array' => __('The specified subscription is invalid'),
            'limit_period.array' => __('The specified period is invalid')
        ];
    }
}
