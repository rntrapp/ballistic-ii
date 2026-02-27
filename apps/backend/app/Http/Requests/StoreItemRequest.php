<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class StoreItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:65535'],
            'description' => ['nullable', 'string', 'max:65535'],
            'status' => ['required', Rule::in(['todo', 'doing', 'done', 'wontdo'])],
            'project_id' => [
                'nullable',
                'uuid',
                Rule::exists('projects', 'id')->where(function ($query) {
                    $query->where('user_id', Auth::id());
                }),
            ],
            'position' => ['integer', 'min:0'],
            'cognitive_load' => ['nullable', 'integer', 'min:1', 'max:10'],
            'scheduled_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:scheduled_date'],
            'recurrence_rule' => ['nullable', 'string', 'max:255'],
            'recurrence_strategy' => ['nullable', 'string', Rule::in(['expires', 'carry_over'])],
            'assignee_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id'),
            ],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [
                'uuid',
                Rule::exists('tags', 'id')->where(function ($query) {
                    $query->where('user_id', Auth::id());
                }),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_id.exists' => 'The selected project does not exist or does not belong to you.',
            'tag_ids.*.exists' => 'One or more selected tags do not exist or do not belong to you.',
            'due_date.after_or_equal' => 'The due date must not be before the scheduled date.',
            'assignee_id.exists' => 'The selected user does not exist.',
        ];
    }
}
