<?php

namespace App\Support;

use App\Models\Business;
use App\Models\ProductVariant;
use App\Models\StatusPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lien court d'un statut WhatsApp (getcauseo.com/c/neeb12) : il ouvre WhatsApp chez le business avec
 * un message pré-rempli qui contient la référence du statut. Meta n'envoyant aucune référence quand
 * un client répond à un statut, c'est le seul moyen fiable de savoir de quel article il parle.
 *
 * short_code = short_slug du business (lettres seulement, fixé au premier statut) + référence
 * (chiffres seulement) : unique sans ambiguïté.
 */
final class StatusLink
{
    /** Longueur du slug du business (lettres a-z). */
    private const SLUG_MIN = 3;

    private const SLUG_MAX = 8;

    /** Slug quand le nom n'a pas assez de lettres. */
    private const SLUG_FALLBACK = 'shop';

    /**
     * Slug du business, créé au premier statut puis jamais changé (les anciens liens restent bons) :
     * le premier mot du nom (« NEEB-RATA MARKET » → « neeb »), suivi d'une lettre s'il est déjà pris.
     */
    public static function reserveSlug(string $businessId): string
    {
        $existing = DB::table('businesses')->where('id', $businessId)->value('short_slug');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $base = self::baseSlug((string) DB::table('businesses')->where('id', $businessId)->value('name'));

        for ($i = 0; ; $i++) {
            $candidate = substr($base, 0, self::SLUG_MAX).self::letters($i);

            if (! DB::table('businesses')->where('short_slug', $candidate)->exists()) {
                DB::table('businesses')->where('id', $businessId)->update(['short_slug' => $candidate]);

                return $candidate;
            }
        }
    }

    /**
     * Référence (1, 2, 3… par business, jamais réutilisée : businesses.last_status_reference) et
     * short_code d'un nouveau statut. À appeler dans une transaction qui verrouille le business
     * (StatusPostController::store).
     */
    public static function assign(StatusPost $post): void
    {
        if ($post->reference !== null && $post->short_code !== null) {
            return;
        }

        $slug = self::reserveSlug((string) $post->business_id);
        $reference = max(
            (int) DB::table('businesses')->where('id', $post->business_id)->value('last_status_reference'),
            (int) StatusPost::query()->where('business_id', $post->business_id)->max('reference'),
        ) + 1;
        DB::table('businesses')->where('id', $post->business_id)->update(['last_status_reference' => $reference]);

        $post->reference = $reference;
        $post->short_code = $slug.$reference;
    }

    /** Adresse du lien court : https://getcauseo.com/c/neeb12. */
    public static function url(StatusPost $post): string
    {
        return rtrim((string) config('services.status_links.url', 'https://getcauseo.com'), '/').'/c/'.$post->short_code;
    }

    /** Lien court sans « https:// », pour la légende (getcauseo.com/c/neeb12). */
    public static function displayUrl(StatusPost $post): string
    {
        return (string) preg_replace('#^https?://#', '', self::url($post));
    }

    /**
     * Message pré-rempli, au vouvoiement : l'article et sa référence, ou seulement la référence pour
     * un statut sans article (image).
     */
    public static function message(StatusPost $post): string
    {
        return $post->product !== null
            ? "Bonjour, je suis intéressé(e) par {$post->product->name} (réf. {$post->reference})"
            : "Bonjour, je vous écris au sujet de votre statut (réf. {$post->reference})";
    }

    /** https://wa.me/22670112233?text=… , ou null sans numéro WhatsApp connu. */
    public static function whatsAppUrl(StatusPost $post): ?string
    {
        $number = preg_replace('/\D/', '', (string) $post->business?->whatsapp_number);

        return $number === '' ? null : "https://wa.me/{$number}?text=".rawurlencode(self::message($post));
    }

    /**
     * Légende prête à copier dans le statut :
     * « Robe wax Awa – 12 500 FCFA
     *   👉 Commander : getcauseo.com/c/neeb12 »
     * Sans prix connu : le nom seul ; sans article : la légende du gérant, s'il y en a une.
     */
    public static function captionSuggestion(StatusPost $post): string
    {
        $title = match (true) {
            $post->product !== null => $post->product->name.(($price = self::price($post)) !== null ? " – {$price}" : ''),
            default => trim((string) $post->caption),
        };

        return ltrim($title."\n👉 Commander : ".self::displayUrl($post));
    }

    /** « 12 500 FCFA », « à partir de 10 000 FCFA » si les variantes diffèrent, ou null. */
    private static function price(StatusPost $post): ?string
    {
        $product = $post->product;
        $prices = $product->variants
            ->map(fn (ProductVariant $variant) => $variant->price ?? $product->base_price)
            ->filter(fn ($price) => $price !== null && (float) $price > 0)
            ->map(fn ($price) => (float) $price);

        if ($prices->isEmpty() && $product->base_price !== null && (float) $product->base_price > 0) {
            $prices = collect([(float) $product->base_price]);
        }

        if ($prices->isEmpty()) {
            return null;
        }

        $format = fn (float $price) => number_format($price, 0, ',', ' ').' FCFA';

        return $prices->min() === $prices->max() ? $format($prices->min()) : 'à partir de '.$format($prices->min());
    }

    /** Premier mot d'au moins 3 lettres du nom, en minuscules sans accents. */
    private static function baseSlug(string $name): string
    {
        $words = preg_split('/[^a-z]+/', strtolower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (strlen($word) >= self::SLUG_MIN) {
                return substr($word, 0, self::SLUG_MAX);
            }
        }

        $joined = implode('', $words);

        return strlen($joined) >= self::SLUG_MIN ? substr($joined, 0, self::SLUG_MAX) : self::SLUG_FALLBACK;
    }

    /** 0 → « », 1 → « a », 26 → « z », 27 → « aa »… */
    private static function letters(int $i): string
    {
        $suffix = '';

        while ($i > 0) {
            $i--;
            $suffix = chr(97 + $i % 26).$suffix;
            $i = intdiv($i, 26);
        }

        return $suffix;
    }
}
