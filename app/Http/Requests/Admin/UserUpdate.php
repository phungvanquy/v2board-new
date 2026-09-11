<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email' => 'required|email:strict',
            'password' => 'nullable|min:8',
            'transfer_enable' => 'numeric',
            'device_limit' => 'nullable|integer',
            'expired_at' => 'nullable|integer',
            'banned' => 'required|in:0,1',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'is_admin' => 'required|in:0,1',
            'is_staff' => 'required|in:0,1',
            'u' => 'integer',
            'd' => 'integer',
            'balance' => 'integer',
            'commission_type' => 'integer',
            'commission_balance' => 'integer',
            'remarks' => 'nullable',
            'speed_limit' => 'nullable|integer'
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'transfer_enable.numeric' => __('The traffic is invalid'),
            'device_limit.integer' => __('The device limit is invalid'),
            'expired_at.integer' => __('The expiration time is invalid'),
            'banned.required' => __('The banned flag cannot be empty'),
            'banned.in' => __('The banned flag is invalid'),
            'is_admin.required' => __('The admin flag cannot be empty'),
            'is_admin.in' => __('The admin flag is invalid'),
            'is_staff.required' => __('The staff flag cannot be empty'),
            'is_staff.in' => __('The staff flag is invalid'),
            'plan_id.integer' => __('The subscription plan is invalid'),
            'commission_rate.integer' => __('The referral commission ratio is invalid'),
            'commission_rate.nullable' => __('The referral commission ratio is invalid'),
            'commission_rate.min' => __('The referral commission ratio cannot be below 0'),
            'commission_rate.max' => __('The referral commission ratio cannot exceed 100'),
            'discount.integer' => __('The exclusive discount ratio is invalid'),
            'discount.nullable' => __('The exclusive discount ratio is invalid'),
            'discount.min' => __('The exclusive discount ratio cannot be below 0'),
            'discount.max' => __('The exclusive discount ratio cannot exceed 100'),
            'u.integer' => __('The upstream traffic is invalid'),
            'd.integer' => __('The downstream traffic is invalid'),
            'balance.integer' => __('The balance is invalid'),
            'commission_balance.integer' => __('The commission is invalid'),
            'password.min' => __('The password must be at least 8 characters'),
            'speed_limit.integer' => __('The speed limit is invalid')
        ];
    }
}
