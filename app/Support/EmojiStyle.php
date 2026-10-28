<?php

namespace App\Support;

use App\Models\Business;

/**
 * Emojis des messages envoyés aux clients, selon le réglage du gérant (reply_style.emojis,
 * « jamais » par défaut). Avec « jamais », seul un 👋 de salutation est gardé ; « parfois »
 * et « souvent » laissent le texte tel quel.
 */
final class EmojiStyle
{
    /** Pictogrammes, symboles, drapeaux, et leurs sélecteurs de variante et liaisons. */
    private const EMOJI = '[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]';

    /** Seul emoji toléré avec « jamais » : une salutation. */
    private const GREETING = '👋';

    /** Marque provisoire d'un emoji retiré (zone à usage privé d'Unicode). */
    private const REMOVED = "\u{E000}";

    public static function apply(Business $business, string $text): string
    {
        return $business->reply_style['emojis'] === 'never' ? self::strip($text) : $text;
    }

    /**
     * Retirer les emojis, sauf le premier 👋. Un emoji qui séparait deux phrases devient un
     * point (« reçu votre capture 🙏 Le responsable… » → « reçu votre capture. Le responsable… »).
     */
    public static function strip(string $text): string
    {
        $kept = false;

        $text = (string) preg_replace_callback('/'.self::GREETING.'\x{FE0F}?|'.self::EMOJI.'+/u', function (array $match) use (&$kept): string {
            if (str_starts_with($match[0], self::GREETING) && ! $kept) {
                $kept = true;

                return $match[0];
            }

            return self::REMOVED;
        }, $text);

        $text = (string) preg_replace('/(?<=[\p{L}\p{N}])[ \t]*'.self::REMOVED.'+[ \t]+(?=\p{Lu})/u', '. ', $text);
        $text = str_replace(self::REMOVED, '', $text);

        return trim((string) preg_replace(['/[ \t]{2,}/u', '/[ \t]+$/mu'], [' ', ''], $text));
    }
}
