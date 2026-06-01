<?php

namespace App\Enums;

enum VehicleType: string
{
    case Bus = 'bus';
    case Buseta = 'buseta';
    case Microbus = 'microbus';
    case Van = 'van';
    case Automobile = 'automobile';

    public function label(): string
    {
        return match ($this) {
            self::Bus => 'Bus',
            self::Buseta => 'Buseta',
            self::Microbus => 'Microbús',
            self::Van => 'Van',
            self::Automobile => 'Automóvil',
        };
    }
}
