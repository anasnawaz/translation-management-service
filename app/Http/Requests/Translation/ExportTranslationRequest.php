<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;

class ExportTranslationRequest extends FormRequest
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
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => [
                'required',
                'string',
                'max:50',
                'distinct',
            ],
        ];
    }
}
