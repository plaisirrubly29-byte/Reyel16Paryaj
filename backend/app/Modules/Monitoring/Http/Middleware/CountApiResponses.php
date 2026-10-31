<?php

namespace App\Modules\Monitoring\Http\Middleware;

use App\Modules\Monitoring\Services\ApiErrorRate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Compte chaque réponse de l'API (et les 5xx) pour l'alerte « erreurs API » de monitoring:check (P9-02). */
class CountApiResponses
{
    public function __construct(private readonly ApiErrorRate $rate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $this->rate->record($response->getStatusCode());

        return $response;
    }
}
