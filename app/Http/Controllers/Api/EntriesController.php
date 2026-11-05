<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Content\Reading\EntryPresenter;
use App\Content\Reading\EntryReader;
use App\Http\Api\ApiError;
use App\Http\Api\ReadParameters;
use App\Http\Controllers\Controller;
use App\Packages\PackageRegistry;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** One route per shape, for whatever types the installed packages declare. Reading never writes. */
final class EntriesController extends Controller
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const SLUG = '/^[a-z0-9][a-z0-9.-]*$/';

    public function __construct(
        private readonly PackageRegistry $registry,
        private readonly EntryReader $reader,
        private readonly EntryPresenter $presenter,
    ) {
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $definition = $this->definition($type);
        $parameters = new ReadParameters($request, $definition);
        $language = $parameters->language();
        $fields = $parameters->fields();
        $sort = $parameters->sort();
        $page = $parameters->page();
        $perPage = $parameters->perPage();

        [$entries, $total] = $this->reader->page($definition->key, $language, $sort, $definition, $page, $perPage);

        return new JsonResponse([
            'data' => $this->presenter->present($entries, $definition, $language, false, $fields),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ], 200, [], self::JSON);
    }

    public function show(Request $request, string $type, string $slug): JsonResponse
    {
        $definition = $this->definition($type);
        $parameters = new ReadParameters($request, $definition);
        $language = $parameters->language();
        $fields = $parameters->fields();
        if (preg_match(self::SLUG, $slug) !== 1) {
            throw ApiError::invalid('The slug is not valid.');
        }

        $entry = $this->reader->find($definition->key, $slug, $language)
            ?? throw ApiError::notFound("There is no {$definition->key} with this slug.");

        return new JsonResponse(
            $this->presenter->present(new Collection([$entry]), $definition, $language, true, $fields)[0],
            200,
            [],
            self::JSON,
        );
    }

    private function definition(string $type): ContentTypeDefinition
    {
        return $this->registry->contentType($type) ?? throw ApiError::notFound("There is no content type \"{$type}\".");
    }
}
