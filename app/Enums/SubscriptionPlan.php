<?php

namespace App\Enums;

/**
 * Formule effective d'un business (même valeurs que plans.slug).
 */
enum SubscriptionPlan: string
{
    case Free = 'free';
    case Starter = 'starter';
    case Pro = 'pro';
    case Business = 'business';
    case Internal = 'internal';
}
