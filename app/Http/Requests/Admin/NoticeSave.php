<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NoticeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title' => 'required',
            'content' => 'required',
            'img_url' => 'nullable|url',
            'tags' => 'nullable|array',
        ];
    }

    public function messages()
    {
        return [
            'title.required' => __('The title cannot be empty'),
            'content.required' => __('Content cannot be empty'),
            'img_url.url' => __('The image URL is invalid'),
            'tags.array' => __('The tag is invalid'),
        ];
    }
}
