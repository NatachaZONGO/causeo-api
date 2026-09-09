<?php

namespace App\Enums;

enum BusinessType: string
{
    case Restaurant = 'restaurant';
    case Boutique = 'boutique';
    case School = 'school';
    case Clinic = 'clinic';
    case Hotel = 'hotel';
    case Salon = 'salon';
    case Pharmacy = 'pharmacy';
    case Other = 'other';
}
