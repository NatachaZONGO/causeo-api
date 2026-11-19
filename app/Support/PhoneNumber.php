<?php

namespace App\Support;

/**
 * Numéro de téléphone saisi par le gérant (vente au comptoir), de n'importe quel pays : gardé en
 * chiffres avec l'indicatif, sans « + », comme les numéros WhatsApp des conversations
 * (« 22670000001 »). Un numéro local, sans indicatif, reçoit celui du pays de l'entreprise.
 */
final class PhoneNumber
{
    /** Indicatifs des pays d'entreprise (businesses.country). */
    private const DIAL_CODES = [
        'BF' => '226', 'CI' => '225', 'ML' => '223', 'SN' => '221', 'NE' => '227', 'TG' => '228',
        'BJ' => '229', 'GN' => '224', 'GH' => '233', 'NG' => '234', 'CM' => '237', 'GA' => '241',
        'CG' => '242', 'CD' => '243', 'TD' => '235', 'MR' => '222', 'MA' => '212', 'FR' => '33',
        'BE' => '32', 'CA' => '1', 'US' => '1',
    ];

    /** Longueur d'un numéro international : 8 à 15 chiffres (E.164). */
    public const PATTERN = '/^\+?[0-9][0-9 .\-()]{5,22}$/';

    /**
     * « +226 70 00 00 01 », « 0022670000001 » → « 22670000001 » ; « 70 00 00 01 » au Burkina →
     * « 22670000001 ». Null si le numéro n'est pas valide.
     */
    public static function normalize(?string $input, ?string $country = null): ?string
    {
        $input = trim((string) $input);
        if ($input === '' || preg_match(self::PATTERN, $input) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $input);
        $international = str_starts_with($input, '+') || str_starts_with($digits, '00');
        $digits = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;

        if (! $international) {
            $code = self::DIAL_CODES[strtoupper((string) $country)] ?? null;
            // Numéro local : sans le 0 de tête (France, Ghana…), précédé de l'indicatif du pays.
            if ($code !== null && ! str_starts_with($digits, $code)) {
                $digits = $code.ltrim($digits, '0');
            }
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }
}
