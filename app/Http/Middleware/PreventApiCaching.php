<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les réponses de /api/* (données des entreprises, conversations, jetons…) ne doivent
 * être gardées ni par le navigateur ni par un proxy. Middleware global placé en tête :
 * il couvre aussi les réponses d'erreur (route inconnue, 413, 429, exceptions).
 */
class PreventApiCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api', 'api/*')) {
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->remove('Expires');
            $response->headers->remove('ETag');
            $response->headers->remove('Last-Modified');
        }

        return $response;
    }
}
