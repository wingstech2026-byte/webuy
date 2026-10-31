<?php

namespace App\Enums;

enum ConversationType: string
{
    case Support = 'support'; // a customer and the WiBuy team
    case Direct = 'direct';   // buyer <-> seller ("Ask the seller"), behind CHAT_DIRECT_ENABLED
}
