<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * Convention de pagination de l'API : ?page=N&per_page=M (M borné à MAX_PER_PAGE).
 *
 * Usage dans un contrôleur :
 *   return TicketResource::collection($query->paginate(Pagination::perPage($request)));
 * La réponse contient alors "data", "links" et "meta" (format standard Laravel).
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public static function perPage(Request $request): int
    {
        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        if ($perPage === false || $perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
