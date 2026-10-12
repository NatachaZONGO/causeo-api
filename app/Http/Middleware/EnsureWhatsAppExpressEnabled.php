<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mode Express (bridge Baileys) : ses webhooks n'existent (404) que s'il est
 * activé explicitement avec un vrai jeton de bridge.
 */
class EnsureWhatsAppExpressEnabled
{
    /** Anciennes valeurs par défaut, publiques : jamais acceptées comme jeton. */
    public const KNOWN_DEFAULT_TOKENS = ['secret_bridge_token_2024'];

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::enabled(), 404);

        return $next($request);
    }

    public static function enabled(): bool
    {
        $token = trim((string) config('services.whatsapp_bridge.token'));

        return config('services.whatsapp_bridge.enabled') === true
            && $token !== ''
            && ! in_array($token, self::KNOWN_DEFAULT_TOKENS, true);
    }
}
