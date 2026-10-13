<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Essai offert à la création d'un business
    |--------------------------------------------------------------------------
    |
    | Formule Pro pendant TRIAL_DAYS jours ou TRIAL_UNITS unités (1 unité = 1 réponse
    | de l'assistant), selon ce qui arrive en premier ; ensuite, formule Gratuit.
    | Les valeurs sont figées dans l'abonnement au début de l'essai : les changer ne
    | touche que les essais suivants.
    |
    */

    'trial_days' => (int) env('TRIAL_DAYS', 15),

    'trial_units' => (int) env('TRIAL_UNITS', 300),

    // Rappels avant la fin de l'essai (jours calendaires, heure de Ouagadougou).
    'trial_reminder_days' => [3, 1],

    // Seuils d'unités notifiés pendant l'essai ; à 100 %, l'essai se termine.
    'trial_unit_thresholds' => [80, 100],

];
