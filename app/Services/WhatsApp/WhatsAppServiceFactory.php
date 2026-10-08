<?php

namespace App\Services\WhatsApp;

use App\Models\Business;

/**
 * Point d'entrée injectable vers WhatsAppService::forBusiness(), pour pouvoir
 * remplacer l'envoi dans les tests.
 */
class WhatsAppServiceFactory
{
    public function forBusiness(Business $business): WhatsAppService
    {
        return WhatsAppService::forBusiness($business);
    }
}
