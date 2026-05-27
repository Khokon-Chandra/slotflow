<?php

declare(strict_types=1);

namespace App\Enums;

enum BookingSource: string
{
    case Web = 'web';
    case Api = 'api';
    case Admin = 'admin';
    case AiAssistant = 'ai_assistant';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Website',
            self::Api => 'API',
            self::Admin => 'Admin',
            self::AiAssistant => 'AI assistant',
        };
    }
}
