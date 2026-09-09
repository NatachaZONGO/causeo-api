<?php

namespace App\Enums;

enum SubscriptionPlan: string
{
    case Free = 'free';
    case Starter = 'starter';
    case Pro = 'pro';
    case Enterprise = 'enterprise';
}
