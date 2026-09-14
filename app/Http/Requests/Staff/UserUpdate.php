<?php

namespace App\Http\Requests\Staff;

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
            'password' => 'nullable',
            'transfer_enable' => 'numeric',
            'device_limit' => 'nullable|integer',
            'expired_at' => 'nullable|integer',
            'banned' => 'required|in:0,1',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'u' => 'integer',
            'd' => 'integer',
            'balance' => 'integer',
            'commission_balance' => 'integer',
        ];
    }

    public function messages()
    {
        return [
            'email.required' => 'Email is required',
            'email.email' => 'Invalid email format',
            'transfer_enable.numeric' => 'Invalid traffic format',
            'device_limit.integer' => 'Invalid device limit format',
            'expired_at.integer' => 'Invalid expiry time format',
            'banned.required' => 'Ban status is required',
            'banned.in' => 'Invalid ban status format',
            'plan_id.integer' => 'Invalid subscription plan format',
            'commission_rate.integer' => 'Invalid referral commission rate format',
            'commission_rate.nullable' => 'Invalid referral commission rate format',
            'commission_rate.min' => 'Referral commission rate minimum is 0',
            'commission_rate.max' => 'Referral commission rate maximum is 100',
            'discount.integer' => 'Invalid exclusive discount rate format',
            'discount.nullable' => 'Invalid exclusive discount rate format',
            'discount.min' => 'Exclusive discount rate minimum is 0',
            'discount.max' => 'Exclusive discount rate maximum is 100',
            'u.integer' => 'Invalid upload traffic format',
            'd.integer' => 'Invalid download traffic format',
            'balance.integer' => 'Invalid balance format',
            'commission_balance.integer' => 'Invalid commission format',
        ];
    }
}
