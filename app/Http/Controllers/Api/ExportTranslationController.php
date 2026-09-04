<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translation\ExportTranslationRequest;
use App\Models\Locale;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ExportTranslationController extends Controller
{
    public function __invoke(
        ExportTranslationRequest $request,
        string $locale
    ): JsonResponse {
        $localeModel = Locale::query()
            ->select(['id', 'code'])
            ->where('code', strtolower($locale))
            ->where('is_active', true)
            ->first();

        if (! $localeModel) {
            // The method return type is JsonResponse; the plain response()
            // helper returns a base Illuminate\Http\Response, which raises
            // a TypeError here instead of producing the intended 404. Using
            // response()->json() matches the declared return type.
            return response()->json([
                'message' => 'Requested resource was not found.',
                'errors' => [
                    'locale' => [
                        'The requested locale does not exist or is inactive.',
                    ],
                ],
            ], 404);
        }

        $tags = $request->validated('tags', []);

        $query = DB::table('translations')
            ->join(
                'translation_keys',
                'translation_keys.id',
                '=',
                'translations.translation_key_id'
            )
            ->where(
                'translations.locale_id',
                $localeModel->id
            )
            ->when(
                $tags !== [],
                function (Builder $query) use ($tags): void {
                    $query->whereExists(
                        function (Builder $tagQuery) use ($tags): void {
                            $tagQuery
                                ->selectRaw('1')
                                ->from('tag_translation')
                                ->join(
                                    'tags',
                                    'tags.id',
                                    '=',
                                    'tag_translation.tag_id'
                                )
                                ->whereColumn(
                                    'tag_translation.translation_id',
                                    'translations.id'
                                )
                                ->whereIn('tags.name', $tags);
                        }
                    );
                }
            );

        $translations = $query->pluck(
            'translations.content',
            'translation_keys.key'
        );

        // An empty keyed collection encodes as a JSON array ([]) rather
        // than an object ({}), since json_encode() has no keys to infer
        // an associative structure from. Frontend consumers expect a flat
        // object of key/content pairs regardless of how many translations
        // exist, so an empty result is cast to an empty object explicitly.
        return response()->json(
            $translations->isEmpty() ? (object) [] : $translations
        );
    }
}
