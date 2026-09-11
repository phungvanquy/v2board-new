<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserFetch extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'filter.*.key' => 'required|in:id,email,transfer_enable,device_limit,d,expired_at,uuid,token,invite_by_email,invite_user_id,plan_id,banned,remarks,is_admin',
            'filter.*.condition' => 'required|in:>,<,=,>=,<=,Fuzzy,!=',
            'filter.*.value' => 'required',
        ];
    }

    public function messages()
    {
        return [
            'filter.*.key.required' => __('The filter key cannot be empty'),
            'filter.*.key.in' => __('The filter key is invalid'),
            'filter.*.condition.required' => __('The filter condition cannot be empty'),
            'filter.*.condition.in' => __('The filter condition is invalid'),
            'filter.*.value.required' => __('The filter value cannot be empty'),
        ];
    }
}
