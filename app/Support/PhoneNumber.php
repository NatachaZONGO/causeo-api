<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Numéros de téléphone des clients, de n'importe quel pays : gardés en chiffres avec l'indicatif,
 * sans « + », comme les numéros WhatsApp des conversations (« 22670000001 »). C'est la clé d'un
 * client (customers.phone) : deux écritures du même numéro doivent donner la même valeur.
 *
 * - normalize() : numéro saisi par le gérant (vente au comptoir). Un numéro local, sans indicatif,
 *   reçoit celui du pays de l'entreprise. Null s'il n'est pas valide.
 * - canonical() : numéro déjà enregistré (wa_id d'une conversation, téléphone d'une commande). Un
 *   numéro international valide reste tel quel ; seul un numéro local reçoit l'indicatif.
 */
final class PhoneNumber
{
    /** Saisie acceptée : chiffres, espaces, points, tirets, parenthèses, « + » en tête. */
    public const PATTERN = '/^\+?[0-9][0-9 .\-()]{5,22}$/';

    /** Pays d'une entreprise sans pays valide. */
    public const DEFAULT_COUNTRY = 'BF';

    /**
     * « +226 70 00 00 01 », « 0022670000001 », « 22670000001 » → « 22670000001 » ; « 70 00 00 01 »
     * au Burkina → « 22670000001 » ; « 0712 345 678 » au Kenya → « 254712345678 ».
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
        $region = self::region($country);

        // Saisie sans « + » : d'abord un numéro local, puis un numéro tapé avec son indicatif.
        $valid = $international
            ? self::valid("+{$digits}", null)
            : (self::valid($digits, $region) ?? self::valid("+{$digits}", null));

        if ($valid === null && ! $international) {
            // Numéro que libphonenumber ne connaît pas encore (nouvelle tranche) : l'indicatif du
            // pays est ajouté, sans le 0 de tête, s'il n'y est pas déjà.
            $code = (string) PhoneNumberUtil::getInstance()->getCountryCodeForRegion($region);
            if ($code !== '0' && ! str_starts_with($digits, $code)) {
                $digits = $code.ltrim($digits, '0');
            }
        }

        $digits = $valid ?? $digits;

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * Numéro enregistré, en chiffres avec l'indicatif. Un wa_id contient déjà son indicatif et reste
     * tel quel ; seul un numéro local reçoit celui du pays de l'entreprise. Un numéro que
     * libphonenumber ne reconnaît pas reste tel quel (chiffres seulement). Chaîne vide sans chiffre.
     */
    public static function canonical(?string $phone, ?string $country = null): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }

        return self::valid("+{$digits}", null)
            ?? self::valid($digits, self::region($country))
            ?? $digits;
    }

    /** Région libphonenumber du pays de l'entreprise (Burkina par défaut). */
    private static function region(?string $country): string
    {
        $country = strtoupper(trim((string) $country));

        return in_array($country, PhoneNumberUtil::getInstance()->getSupportedRegions(), true) ? $country : self::DEFAULT_COUNTRY;
    }

    /** Chiffres E.164 (sans « + ») si le numéro est valide, sinon null. */
    private static function valid(string $number, ?string $region): ?string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($number, $region);
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($parsed) ? ltrim($util->format($parsed, PhoneNumberFormat::E164), '+') : null;
    }
}
