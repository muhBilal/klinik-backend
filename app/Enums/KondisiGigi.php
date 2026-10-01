<?php

namespace App\Enums;

/**
 * Kondisi gigi di odontogram (PRD DG-01). Kode tiga huruf mengikuti notasi odontogram yang lazim di rekam medis gigi
 * Indonesia (sou/car/amf/cof/mis/rct/...); daftar dan warnanya wajib ditinjau dokter gigi penanggung jawab klinik.
 *
 * - `cakupan()`: `permukaan` (dicatat per permukaan M/O/D/B/L) atau `gigi` (seluruh gigi).
 * - `kelompok()`: kondisi seluruh gigi dalam kelompok eksklusif saling menggantikan (mis. satu jenis mahkota per gigi);
 *   kelompok `lain` tidak eksklusif. Satu permukaan hanya punya satu kondisi.
 * - `mengakhiri()`: kelompok lain yang ikut diakhiri saat kondisi ini dicatat (mis. gigi hilang mengakhiri tambalan).
 */
enum KondisiGigi: string
{
    // Per permukaan
    case Karies = 'car';
    case Amalgam = 'amf';
    case Komposit = 'cof';
    case Gic = 'gif';
    case FissureSealant = 'fis';
    case Inlay = 'inl';
    case Onlay = 'onl';

    // Keberadaan gigi
    case BelumErupsi = 'une';
    case ErupsiSebagian = 'pre';
    case Impaksi = 'imv';
    case Hilang = 'mis';
    case SisaAkar = 'rrx';
    case Implan = 'ipx';

    // Mahkota
    case MahkotaLogam = 'fmc';
    case MahkotaPorselen = 'poc';
    case MahkotaMetalPorselen = 'mpc';
    case MahkotaEmas = 'gmc';

    // Jembatan & gigi tiruan
    case Abutment = 'abu';
    case Pontik = 'pon';
    case GigiTiruanSebagian = 'prd';
    case GigiTiruanPenuh = 'fld';

    // Pulpa
    case NonVital = 'nvt';
    case SaluranAkar = 'rct';

    // Lain-lain (tidak eksklusif)
    case Fraktur = 'cfr';
    case Atrisi = 'att';
    case Abrasi = 'abr';
    case Anomali = 'ano';
    case Diastema = 'dia';
    case Migrasi = 'mig';

    public const PERMUKAAN = 'permukaan';

    public function label(): string
    {
        return match ($this) {
            self::Karies => 'Karies',
            self::Amalgam => 'Tambalan amalgam',
            self::Komposit => 'Tambalan komposit',
            self::Gic => 'Tambalan GIC (glass ionomer)',
            self::FissureSealant => 'Fissure sealant',
            self::Inlay => 'Inlay',
            self::Onlay => 'Onlay',
            self::BelumErupsi => 'Belum erupsi',
            self::ErupsiSebagian => 'Erupsi sebagian',
            self::Impaksi => 'Impaksi',
            self::Hilang => 'Gigi hilang',
            self::SisaAkar => 'Sisa akar',
            self::Implan => 'Implan',
            self::MahkotaLogam => 'Mahkota logam penuh',
            self::MahkotaPorselen => 'Mahkota porselen',
            self::MahkotaMetalPorselen => 'Mahkota metal-porselen',
            self::MahkotaEmas => 'Mahkota emas',
            self::Abutment => 'Abutment jembatan',
            self::Pontik => 'Pontik jembatan',
            self::GigiTiruanSebagian => 'Gigi tiruan sebagian lepasan',
            self::GigiTiruanPenuh => 'Gigi tiruan penuh lepasan',
            self::NonVital => 'Gigi non-vital',
            self::SaluranAkar => 'Perawatan saluran akar',
            self::Fraktur => 'Fraktur mahkota',
            self::Atrisi => 'Atrisi',
            self::Abrasi => 'Abrasi',
            self::Anomali => 'Anomali',
            self::Diastema => 'Diastema',
            self::Migrasi => 'Migrasi / rotasi',
        };
    }

    public function cakupan(): string
    {
        return $this->kelompok() === self::PERMUKAAN ? 'permukaan' : 'gigi';
    }

    public function kelompok(): string
    {
        return match ($this) {
            self::Karies, self::Amalgam, self::Komposit, self::Gic, self::FissureSealant, self::Inlay, self::Onlay => self::PERMUKAAN,
            self::BelumErupsi, self::ErupsiSebagian, self::Impaksi, self::Hilang, self::SisaAkar, self::Implan => 'keberadaan',
            self::MahkotaLogam, self::MahkotaPorselen, self::MahkotaMetalPorselen, self::MahkotaEmas => 'mahkota',
            self::Abutment, self::Pontik => 'jembatan',
            self::GigiTiruanSebagian, self::GigiTiruanPenuh => 'protesa',
            self::NonVital, self::SaluranAkar => 'pulpa',
            default => 'lain',
        };
    }

    public function eksklusif(): bool
    {
        return $this->kelompok() !== 'lain';
    }

    /** @return list<string> kelompok kondisi lain di gigi yang sama yang ikut diakhiri */
    public function mengakhiri(): array
    {
        return match ($this) {
            self::Hilang => [self::PERMUKAAN, 'mahkota', 'pulpa', 'lain'],
            self::SisaAkar => [self::PERMUKAAN, 'mahkota'],
            self::Implan, self::Pontik => [self::PERMUKAAN, 'pulpa'],
            self::MahkotaLogam, self::MahkotaPorselen, self::MahkotaMetalPorselen, self::MahkotaEmas => [self::PERMUKAAN],
            default => [],
        };
    }

    /** Kelompok yang masih boleh dicatat pada gigi berstatus hilang (pengganti gigi). @return list<string> */
    public static function bolehSaatHilang(): array
    {
        return ['keberadaan', 'jembatan', 'protesa'];
    }

    /** Warna tampilan odontogram (hex). */
    public function warna(): string
    {
        return match ($this) {
            self::Karies => '#dc2626',
            self::Amalgam => '#334155',
            self::Komposit => '#0284c7',
            self::Gic => '#0d9488',
            self::FissureSealant => '#65a30d',
            self::Inlay, self::Onlay => '#d97706',
            self::BelumErupsi, self::ErupsiSebagian, self::Impaksi => '#7c3aed',
            self::Hilang => '#0f172a',
            self::SisaAkar => '#b91c1c',
            self::Implan => '#4f46e5',
            self::MahkotaLogam, self::MahkotaEmas => '#a16207',
            self::MahkotaPorselen, self::MahkotaMetalPorselen => '#0369a1',
            self::Abutment, self::Pontik => '#9333ea',
            self::GigiTiruanSebagian, self::GigiTiruanPenuh => '#c026d3',
            self::NonVital => '#78716c',
            self::SaluranAkar => '#ea580c',
            default => '#64748b',
        };
    }

    /** Data referensi untuk frontend (satu sumber label, cakupan, kelompok & warna). */
    public static function referensi(): array
    {
        return array_map(fn (self $k) => [
            'kode' => $k->value,
            'label' => $k->label(),
            'cakupan' => $k->cakupan(),
            'kelompok' => $k->kelompok(),
            'warna' => $k->warna(),
        ], self::cases());
    }
}
