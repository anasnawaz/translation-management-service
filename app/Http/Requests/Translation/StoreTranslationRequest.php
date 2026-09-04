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
        // Note: `locale` is read via input() rather than the magic
        // `$this->locale` property accessor. Symfony's base Request class
        // declares a real (protected) `$locale` property used for HTTP
        // locale negotiation, which shadows Laravel's `__get()` magic
        // getter for request input of the same name. Accessing
        // `$this->locale` here would silently return the request's HTTP
        // locale (defaulting to `config('app.locale')`, i.e. "en") instead
        // of the submitted `locale` field, causing every translation to
        // be created for the wrong locale unless the client happened to
        // send "en".
        $this->merge([
            'key' => strtolower(trim((string) $this->key)),
            'locale' => strtolower(trim((string) $this->input('locale'))),
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
