<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;

class ExportTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize `tags`, whichever of the two accepted shapes it arrives in:
     * a comma-separated string (`tags=web,mobile`) or a repeated-array
     * query parameter (`tags[]=web&tags[]=mobile`). Both are normalized
     * identically - trimmed, lowercased, emptied values dropped, and
     * de-duplicated - before validation runs, so `tags=Web,WEB` and
     * `tags[]=Web&tags[]=WEB` behave the same way.
     *
     * Non-string elements (e.g. a nested array or object passed as one of
     * the `tags[]` values) are deliberately left untouched rather than
     * cast to a string, so the `tags.*` => 'string' validation rule still
     * rejects them instead of silently accepting a stringified "Array".
     */
    protected function prepareForValidation(): void
    {
        $tags = $this->input('tags');

        if ($tags === null) {
            return;
        }

        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        if (! is_array($tags)) {
            return;
        }

        $this->merge([
            'tags' => collect($tags)
                ->map(
                    fn (mixed $tag): mixed => is_string($tag)
                        ? strtolower(trim($tag))
                        : $tag
                )
                ->filter(
                    fn (mixed $tag): bool => ! is_string($tag)
                        || $tag !== ''
                )
                ->unique()
                ->values()
                ->all(),
        ]);
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
