<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServerShadowsocksSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'show' => '',
            'name' => 'required',
            'group_id' => 'required|array',
            'parent_id' => 'nullable|integer',
            'route_id' => 'nullable|array',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'cipher' => 'required|in:aes-128-gcm,aes-192-gcm,aes-256-gcm,chacha20-ietf-poly1305,2022-blake3-aes-128-gcm,2022-blake3-aes-256-gcm',
            'obfs' => 'nullable|in:http',
            'obfs_settings' => 'nullable|array',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric'
        ];
    }

    public function messages()
    {
        return [
            'name.required' => __('The node name cannot be empty'),
            'group_id.required' => __('The permission group cannot be empty'),
            'group_id.array' => __('The permission group is invalid'),
            'route_id.array' => __('The route group is invalid'),
            'parent_id.integer' => __('The parent node is invalid'),
            'host.required' => __('The node address cannot be empty'),
            'port.required' => __('The connection port cannot be empty'),
            'server_port.required' => __('The backend service port cannot be empty'),
            'cipher.required' => __('The cipher cannot be empty'),
            'tags.array' => __('The tag is invalid'),
            'rate.required' => __('The rate cannot be empty'),
            'rate.numeric' => __('The rate is invalid'),
            'obfs.in' => __('The obfuscation is invalid'),
            'obfs_settings.array' => __('The obfuscation settings are invalid')
        ];
    }
}
