<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('tags')) {
            $this->merge([
                'tags' => collect($this->input('tags', []))
                    ->map(fn (mixed $tag): string => strtolower(trim((string) $tag)))
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
            'content' => ['sometimes', 'required', 'string'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9._-]+$/',
                'distinct',
            ],
        ];
    }
}
