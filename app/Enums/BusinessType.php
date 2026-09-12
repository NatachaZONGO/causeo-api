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
    case Photographe = 'photographe';
    case SalonBeaute = 'salon_beaute';
    case CabinetMedical = 'cabinet_medical';
    case AgenceServices = 'agence_services';
    case Nettoyage = 'nettoyage';
    case Communication = 'communication';
    case Formation = 'formation';
    case Other = 'other';
}
