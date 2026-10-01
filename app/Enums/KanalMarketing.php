<?php

namespace App\Enums;

/** Saluran promosi yang disetujui pasien pada opt-in marketing (PS-04; dipakai broadcast CRM CR-03 nanti). */
enum KanalMarketing: string
{
    case Whatsapp = 'whatsapp';
    case Sms = 'sms';
    case Email = 'email';
    case Telepon = 'telepon';

    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'WhatsApp',
            self::Sms => 'SMS',
            self::Email => 'Email',
            self::Telepon => 'Telepon',
        };
    }
}
