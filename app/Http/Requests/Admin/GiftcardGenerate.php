<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GiftcardGenerate extends FormRequest
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
            'type' => 'required|in:1,2,3,4,5',
            'value' => ['required_if:type,1,2,3,5', 'nullable', 'integer'],
            'plan_id' => ['required_if:type,5', 'nullable', 'integer'],
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer',
            'limit_use' => 'nullable|integer',
            'code' => '',
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
            'value.required' => __('The value cannot be empty'),
            'value.integer' => __('The value is invalid'),
            'plan_id.required' => __('The subscription cannot be empty'),
            'started_at.required' => __('The start time cannot be empty'),
            'started_at.integer' => __('The start time is invalid'),
            'ended_at.required' => __('The end time cannot be empty'),
            'ended_at.integer' => __('The end time is invalid'),
            'limit_use.integer' => __('The maximum number of uses is invalid'),
        ];
    }
}
