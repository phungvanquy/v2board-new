<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServerTrojanSave extends FormRequest
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
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'network' => 'required',
            'network_settings' => 'nullable',
            'allow_insecure' => 'nullable|in:0,1',
            'server_name' => 'nullable',
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
            'allow_insecure.in' => __('The allow insecure setting is invalid'),
            'tags.array' => __('The tag is invalid'),
            'rate.required' => __('The rate cannot be empty'),
            'rate.numeric' => __('The rate is invalid')
        ];
    }
}
