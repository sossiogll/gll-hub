<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Content\Reading\Languages;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class LanguagesController extends Controller
{
    public function __invoke(Languages $languages): JsonResponse
    {
        return new JsonResponse(['default' => $languages->default(), 'available' => $languages->available()]);
    }
}
