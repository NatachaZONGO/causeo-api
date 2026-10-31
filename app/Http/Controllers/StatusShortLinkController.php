<?php

namespace App\Http\Controllers;

use App\Models\StatusPost;
use App\Support\StatusLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Lien court d'un statut WhatsApp : getcauseo.com/c/neeb12 → 302 vers wa.me/{numéro}?text={message}
 * avec la référence du statut, et un clic de plus. Route publique sans session ni cookie : aucune
 * donnée du visiteur n'est enregistrée (ni IP, ni user-agent), seul le compteur augmente.
 *
 * Le lien reste valable après l'expiration du statut, tant que le statut et son article existent ;
 * sinon, page « lien expiré ».
 */
class StatusShortLinkController extends Controller
{
    public function __invoke(string $code): RedirectResponse|Response
    {
        $post = StatusPost::query()->with(['business:id,whatsapp_number', 'product:id,name'])->where('short_code', strtolower($code))->first();

        // Article supprimé (product_id remis à null) : le message ne peut plus le nommer.
        if ($post === null || ($post->product_id === null && $post->media_id === null)) {
            return $this->expired();
        }

        $url = StatusLink::whatsAppUrl($post);

        if ($url === null) {
            Log::warning('StatusShortLinkController: lien de statut sans numéro WhatsApp du business', ['business_id' => $post->business_id]);

            return $this->expired();
        }

        // Sans toucher à updated_at : seul le compteur change.
        StatusPost::query()->whereKey($post->id)->toBase()->increment('clicks');

        return redirect()->away($url, 302)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function expired(): Response
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Lien expiré</title>
<style>body{font-family:system-ui,sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;color:#111827;padding:16px}main{max-width:420px;text-align:center}h1{font-size:1.4rem}p{color:#4b5563;line-height:1.5}</style>
</head>
<body>
<main>
<h1>Ce lien a expiré</h1>
<p>L'article de ce statut n'est plus disponible. Écrivez directement à la boutique sur WhatsApp pour connaître ses nouveautés.</p>
</main>
</body>
</html>
HTML;

        return response($html, 404, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
