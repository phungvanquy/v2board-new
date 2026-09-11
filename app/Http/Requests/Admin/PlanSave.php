<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlanSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name' => 'required',
            'content' => '',
            'group_id' => 'required',
            'transfer_enable' => 'required',
            'device_limit' => 'nullable|integer',
            'month_price' => 'nullable|integer',
            'quarter_price' => 'nullable|integer',
            'half_year_price' => 'nullable|integer',
            'year_price' => 'nullable|integer',
            'two_year_price' => 'nullable|integer',
            'three_year_price' => 'nullable|integer',
            'onetime_price' => 'nullable|integer',
            'reset_price' => 'nullable|integer',
            'reset_traffic_method' => 'nullable|integer|in:0,1,2,3,4',
            'capacity_limit' => 'nullable|integer',
            'speed_limit' => 'nullable|integer'
        ];
    }

    public function messages()
    {
        return [
            'name.required' => __('The plan name cannot be empty'),
            'type.required' => __('The plan type cannot be empty'),
            'type.in' => __('The plan type is invalid'),
            'group_id.required' => __('The permission group cannot be empty'),
            'transfer_enable.required' => __('The traffic cannot be empty'),
            'device_limit.integer' => __('The device limit format is invalid'),
            'month_price.integer' => __('The monthly billing amount is invalid'),
            'quarter_price.integer' => __('The quarterly billing amount is invalid'),
            'half_year_price.integer' => __('The semi-annual billing amount is invalid'),
            'year_price.integer' => __('The annual billing amount is invalid'),
            'two_year_price.integer' => __('The two-year billing amount is invalid'),
            'three_year_price.integer' => __('The three-year billing amount is invalid'),
            'onetime_price.integer' => __('The one-time amount is invalid'),
            'reset_price.integer' => __('The data reset package amount is invalid'),
            'reset_traffic_method.integer' => __('The traffic reset method is invalid'),
            'reset_traffic_method.in' => __('The traffic reset method is invalid'),
            'capacity_limit.integer' => __('The user capacity limit is invalid'),
            'speed_limit.integer' => __('The speed limit format is invalid')
        ];
    }
}
