<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case AnsweredByAi = 'answered_by_ai';
    case Escalated = 'escalated';
    case AnsweredByHuman = 'answered_by_human';
}
