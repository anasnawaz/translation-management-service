<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'key' => strtolower(trim((string) $this->key)),
            'locale' => strtolower(trim((string) $this->locale)),
            'tags' => collect($this->input('tags', []))
                ->map(fn (mixed $tag): string => strtolower(trim((string) $tag)))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9._-]+$/',
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'locale' => [
                'required',
                'string',
                Rule::exists('locales', 'code')
                    ->where('is_active', true),
            ],
            'content' => ['required', 'string'],
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
