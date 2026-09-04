<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translation\ListTranslationRequest;
use App\Http\Requests\Translation\StoreTranslationRequest;
use App\Http\Requests\Translation\UpdateTranslationRequest;
use App\Http\Resources\TranslationResource;
use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Database\Eloquent\Builder;
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
                $this->applyContentSearch($query, $content);
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
                    $nestedQuery->whereHas(
                        'translationKey',
                        fn ($keyQuery) => $keyQuery->where(
                            'key',
                            'like',
                            strtolower(trim($search)).'%'
                        )
                    );

                    $this->applyContentSearch(
                        $nestedQuery,
                        $search,
                        or: true
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

    /**
     * Apply a content search to the given query.
     *
     * MySQL uses the `content` FULLTEXT index via `whereFullText()`. SQLite
     * (used by the automated test suite) does not support FULLTEXT
     * searching, so a `LIKE` fallback is used there instead. This keeps the
     * MySQL production optimization intact while remaining testable on
     * SQLite.
     *
     * @param  Builder<Translation>  $query
     */
    private function applyContentSearch(
        Builder $query,
        string $content,
        bool $or = false
    ): void {
        if ($this->supportsFullTextSearch($query)) {
            $or
                ? $query->orWhereFullText('translations.content', $content)
                : $query->whereFullText('translations.content', $content);

            return;
        }

        $or
            ? $query->orWhere('translations.content', 'like', '%'.$content.'%')
            : $query->where('translations.content', 'like', '%'.$content.'%');
    }

    /**
     * @param  Builder<Translation>  $query
     */
    private function supportsFullTextSearch(Builder $query): bool
    {
        return $query->getConnection()->getDriverName() === 'mysql';
    }
}
