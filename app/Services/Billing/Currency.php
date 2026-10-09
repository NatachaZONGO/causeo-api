<?php

namespace App\Services\Billing;

/**
 * Devise d'affichage des prix selon le pays du business : XOF dans l'UEMOA,
 * EUR dans la zone euro, USD ailleurs. Les paiements ne sont possibles qu'en XOF.
 */
final class Currency
{
    public const XOF = 'XOF';

    public const EUR = 'EUR';

    public const USD = 'USD';

    public const SUPPORTED = [self::XOF, self::EUR, self::USD];

    /** Devise dans laquelle on encaisse (Orange Money, Moov Money). */
    public const PAYABLE = [self::XOF];

    /** UEMOA : Bénin, Burkina Faso, Côte d'Ivoire, Guinée-Bissau, Mali, Niger, Sénégal, Togo. */
    private const UEMOA = ['BJ', 'BF', 'CI', 'GW', 'ML', 'NE', 'SN', 'TG'];

    /** Zone euro (21 pays, Bulgarie comprise depuis le 1er janvier 2026). */
    private const EUROZONE = [
        'AT', 'BE', 'BG', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK',
    ];

    public static function forCountry(?string $country): string
    {
        $country = strtoupper(trim((string) $country)) ?: 'BF';

        return match (true) {
            in_array($country, self::UEMOA, true) => self::XOF,
            in_array($country, self::EUROZONE, true) => self::EUR,
            default => self::USD,
        };
    }

    public static function isPayable(string $currency): bool
    {
        return in_array($currency, self::PAYABLE, true);
    }
}
