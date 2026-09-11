<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServerVmessSave extends FormRequest
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
            'tls' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'network' => 'required|in:tcp,kcp,ws,http,domainsocket,quic,grpc,httpupgrade,xhttp',
            'networkSettings' => 'nullable|array',
            'networkSettings.security' => 'nullable|in:auto,aes-128-gcm,chacha20-poly1305,none',
            'ruleSettings' => 'nullable|array',
            'tlsSettings' => 'nullable|array',
            'dnsSettings' => 'nullable|array',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => __('The node name cannot be empty'),
            'group_id.required' => __('The permission group cannot be empty'),
            'group_id.array' => __('The permission group is invalid'),
            'route_id.array' => __('The route group is invalid'),
            'parent_id.integer' => __('The parent ID is invalid'),
            'host.required' => __('The node address cannot be empty'),
            'port.required' => __('The connection port cannot be empty'),
            'server_port.required' => __('The backend service port cannot be empty'),
            'tls.required' => __('TLS cannot be empty'),
            'tags.array' => __('The tag is invalid'),
            'rate.required' => __('The rate cannot be empty'),
            'rate.numeric' => __('The rate is invalid'),
            'network.required' => __('The transport protocol cannot be empty'),
            'network.in' => __('The transport protocol is invalid'),
            'networkSettings.array' => __('The transport protocol configuration is invalid'),
            'networkSettings.security.in' => __('The vmess encryption type must be one of: auto, aes-128-gcm, chacha20-poly1305, none'),
            'ruleSettings.array' => __('The rule configuration is invalid'),
            'tlsSettings.array' => __('The TLS configuration is invalid'),
            'dnsSettings.array' => __('The DNS configuration is invalid'),
        ];
    }
}
