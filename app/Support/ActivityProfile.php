<?php

namespace App\Support;

use App\Models\ProductVariant;

/**
 * Profil d'activité d'un business : un seul moteur de catalogue (products, product_variants,
 * stock_movements), présenté selon l'activité. Chaque profil définit le nom du module et des
 * éléments, les catégories proposées, les types d'options, le suivi de la disponibilité et le
 * module relié (commandes ou rendez-vous). hotel, formation et immobilier viendront sur le même
 * principe (PLANNED).
 */
final class ActivityProfile
{
    public const BOUTIQUE = 'boutique';

    public const RESTAURANT = 'restaurant';

    public const SERVICES = 'services';

    /** Profils utilisables aujourd'hui. */
    public const AVAILABLE = [self::BOUTIQUE, self::RESTAURANT, self::SERVICES];

    /** Profils prévus, pas encore utilisables. */
    public const PLANNED = ['hotel', 'formation', 'immobilier'];

    /** Stock en quantité (boutique). */
    public const TRACK_QUANTITY = 'quantity';

    /** « Disponible aujourd'hui / épuisé », remis à disponible chaque matin ou à la main (restaurant). */
    public const TRACK_DAILY = 'daily';

    /** Pas de stock (prestations). */
    public const TRACK_NONE = 'none';

    /** Profil déduit du modèle choisi à l'inscription (businesses.type). */
    private const BY_TYPE = [
        'restaurant' => self::RESTAURANT,
        'boutique' => self::BOUTIQUE,
        'pharmacy' => self::BOUTIQUE,
        'salon' => self::SERVICES,
        'salon_beaute' => self::SERVICES,
        'photographe' => self::SERVICES,
        'cabinet_medical' => self::SERVICES,
        'clinic' => self::SERVICES,
        'agence_services' => self::SERVICES,
        'nettoyage' => self::SERVICES,
        'communication' => self::SERVICES,
        'garage' => self::SERVICES,
        'auto_ecole' => self::SERVICES,
        // En attendant leurs profils (PLANNED).
        'hotel' => self::SERVICES,
        'formation' => self::SERVICES,
        'school' => self::SERVICES,
    ];

    private const DEFINITIONS = [
        self::BOUTIQUE => [
            'label' => 'Boutique',
            'module_label' => 'Catalogue',
            'item_singular' => 'article',
            'item_plural' => 'articles',
            'categories' => ['Vêtements', 'Chaussures', 'Sacs', 'Accessoires', 'Beauté'],
            'option_types' => [
                ['key' => 'taille', 'label' => 'Taille', 'priced' => false],
                ['key' => 'couleur', 'label' => 'Couleur', 'priced' => false],
                ['key' => 'pointure', 'label' => 'Pointure', 'priced' => false],
            ],
            'tracking' => self::TRACK_QUANTITY,
            'linked_module' => 'orders',
            'product_fields' => ['stock'],
            'availability_labels' => [
                ProductVariant::AVAILABLE => 'disponible',
                ProductVariant::LOW_STOCK => 'dernières pièces',
                ProductVariant::OUT_OF_STOCK => 'en rupture',
            ],
            'fulfillment_labels' => ['delivery' => 'Livraison', 'pickup' => 'Retrait en boutique', 'shipping' => 'Expédition', 'dine_in' => 'Sur place'],
        ],
        self::RESTAURANT => [
            'label' => 'Restaurant',
            'module_label' => 'Menu',
            'item_singular' => 'plat',
            'item_plural' => 'plats',
            'categories' => ['Entrées', 'Plats', 'Desserts', 'Boissons', 'Formules'],
            'option_types' => [
                ['key' => 'portion', 'label' => 'Portion', 'priced' => false],
                ['key' => 'accompagnement', 'label' => 'Accompagnement', 'priced' => false],
                ['key' => 'supplement', 'label' => 'Supplément', 'priced' => true],
            ],
            'tracking' => self::TRACK_DAILY,
            'linked_module' => 'orders',
            'product_fields' => ['daily_availability', 'extras', 'preparation_minutes'],
            'availability_labels' => [
                ProductVariant::AVAILABLE => 'disponible aujourd\'hui',
                ProductVariant::LOW_STOCK => 'disponible aujourd\'hui',
                ProductVariant::OUT_OF_STOCK => 'épuisé aujourd\'hui',
            ],
            'fulfillment_labels' => ['delivery' => 'Livraison', 'pickup' => 'À emporter', 'shipping' => 'Expédition', 'dine_in' => 'Sur place'],
        ],
        self::SERVICES => [
            'label' => 'Prestations de services',
            'module_label' => 'Prestations',
            'item_singular' => 'prestation',
            'item_plural' => 'prestations',
            'categories' => ['Coiffure', 'Soins', 'Maquillage', 'Ongles', 'Massage'],
            'option_types' => [
                ['key' => 'duree', 'label' => 'Durée (minutes)', 'priced' => false],
                ['key' => 'niveau', 'label' => 'Niveau', 'priced' => false],
            ],
            'tracking' => self::TRACK_NONE,
            'linked_module' => 'appointments',
            'product_fields' => ['duration_minutes'],
            'availability_labels' => [],
            'fulfillment_labels' => ['delivery' => 'À domicile', 'pickup' => 'Sur place', 'shipping' => 'Expédition', 'dine_in' => 'Sur place'],
        ],
    ];

    /** Profil d'un modèle d'inscription ; boutique pour un modèle inconnu. */
    public static function forType(?string $type): string
    {
        return self::BY_TYPE[$type ?? ''] ?? self::BOUTIQUE;
    }

    /**
     * Définition complète d'un profil, telle que l'API la renvoie (clé comprise).
     *
     * @return array<string, mixed>
     */
    public static function definition(string $profile): array
    {
        $profile = in_array($profile, self::AVAILABLE, true) ? $profile : self::BOUTIQUE;

        return ['key' => $profile] + self::DEFINITIONS[$profile];
    }

    /**
     * Profils utilisables, puis profils prévus (available: false).
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            ...array_map(fn (string $profile) => self::definition($profile) + ['available' => true], self::AVAILABLE),
            ...array_map(fn (string $profile) => ['key' => $profile, 'label' => ucfirst($profile), 'available' => false], self::PLANNED),
        ];
    }

    /** Disponibilité dite au client, ou null pour un profil sans disponibilité (prestations). */
    public static function availabilityLabel(string $profile, string $availability): ?string
    {
        return self::definition($profile)['availability_labels'][$availability] ?? null;
    }

    /** 120 → « 2 h », 90 → « 1 h 30 », 45 → « 45 min ». */
    public static function duration(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest} min",
            $rest === 0 => "{$hours} h",
            default => "{$hours} h ".str_pad((string) $rest, 2, '0', STR_PAD_LEFT),
        };
    }
}
