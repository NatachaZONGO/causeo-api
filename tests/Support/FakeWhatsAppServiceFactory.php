<?php

namespace Tests\Support;

use App\Models\Business;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceFactory;

/**
 * Fabrique factice (classe nommée : le traitement du webhook est sérialisé par la
 * file « sync », ce qu'une classe anonyme ou un mock ne permettent pas).
 */
class FakeWhatsAppServiceFactory extends WhatsAppServiceFactory
{
    public function forBusiness(Business $business): WhatsAppService
    {
        return new FakeWhatsAppService;
    }
}
