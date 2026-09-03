<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translation\ListTranslationRequest;
use App\Http\Requests\Translation\StoreTranslationRequest;
use App\Http\Requests\Translation\UpdateTranslationRequest;
use App\Http\Resources\TranslationResource;
use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TranslationController extends Controller
{
    public function __construct(
        private readonly TranslationService $translationService
    ) {}

    public function index(
        ListTranslationRequest $request
    ): AnonymousResourceCollection {
        $filters = $request->validated();

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $perPage = $filters['per_page'] ?? 25;

        $query = Translation::query()
            ->select([
                'translations.id',
                'translations.translation_key_id',
                'translations.locale_id',
                'translations.content',
                'translations.created_at',
                'translations.updated_at',
            ])
            ->with([
                'translationKey:id,key,description',
                'locale:id,name,code',
                'tags:id,name',
            ]);

        $query->when(
            $filters['key'] ?? null,
            function ($query, string $key): void {
                $query->whereHas(
                    'translationKey',
                    fn ($keyQuery) => $keyQuery->where(
                        'key',
                        'like',
                        strtolower(trim($key)).'%'
                    )
                );
            }
        );

        $query->when(
            $filters['content'] ?? null,
            function ($query, string $content): void {
                $query->whereFullText('translations.content', $content);
            }
        );

        $query->when(
            $filters['locale'] ?? null,
            function ($query, string $locale): void {
                $query->whereHas(
                    'locale',
                    fn ($localeQuery) => $localeQuery->where(
                        'code',
                        strtolower($locale)
                    )
                );
            }
        );

        $query->when(
            $filters['tags'] ?? null,
            function ($query, array $tags): void {
                $query->whereHas(
                    'tags',
                    fn ($tagQuery) => $tagQuery->whereIn('name', $tags)
                );
            }
        );

        $query->when(
            $filters['search'] ?? null,
            function ($query, string $search): void {
                $query->where(function ($nestedQuery) use ($search): void {
                    $nestedQuery
                        ->whereHas(
                            'translationKey',
                            fn ($keyQuery) => $keyQuery->where(
                                'key',
                                'like',
                                strtolower(trim($search)).'%'
                            )
                        )
                        ->orWhereFullText(
                            'translations.content',
                            $search
                        );
                });
            }
        );

        $translations = $query
            ->orderBy($sortBy, $sortDirection)
            ->cursorPaginate($perPage)
            ->withQueryString();

        return TranslationResource::collection($translations);
    }

    public function store(
        StoreTranslationRequest $request
    ): JsonResponse {
        $translation = $this->translationService->create(
            $request->validated()
        );

        return (new TranslationResource($translation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Translation $translation
    ): TranslationResource {
        $translation->load([
            'translationKey',
            'locale',
            'tags',
        ]);

        return new TranslationResource($translation);
    }

    public function update(
        UpdateTranslationRequest $request,
        Translation $translation
    ): TranslationResource {
        $translation = $this->translationService->update(
            $translation,
            $request->validated()
        );

        return new TranslationResource($translation);
    }

    public function destroy(
        Translation $translation
    ): JsonResponse {
        $translation->delete();

        return response()->json(null, 204);
    }
}
