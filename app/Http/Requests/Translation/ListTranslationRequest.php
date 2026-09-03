<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->tags)) {
            $this->merge([
                'tags' => collect(explode(',', $this->tags))
                    ->map(
                        fn (string $tag): string => strtolower(trim($tag))
                    )
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:255'],
            'locale' => [
                'nullable',
                'string',
                Rule::exists('locales', 'code'),
            ],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:50', 'distinct'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => [
                'nullable',
                Rule::in(['id', 'created_at', 'updated_at']),
            ],
            'sort_direction' => [
                'nullable',
                Rule::in(['asc', 'desc']),
            ],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
