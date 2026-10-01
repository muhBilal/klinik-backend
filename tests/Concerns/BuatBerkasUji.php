<?php

namespace Tests\Concerns;

/**
 * Data biner untuk test berkas & tanda tangan. Image Docker tidak punya GD, jadi gambar dibuat manual.
 */
trait BuatBerkasUji
{
    /** JPEG 1x1 piksel (image/jpeg terdeteksi dari isinya). */
    protected static function jpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }

    /** Gambar PNG sah (grayscale 16x16 acak) sebagai data URL, pengganti coretan tanda tangan dari canvas. */
    protected function ttd(): string
    {
        $raw = '';
        for ($y = 0; $y < 16; $y++) {
            $raw .= "\0".random_bytes(16);
        }
        $chunk = fn (string $tipe, string $data) => pack('N', strlen($data)).$tipe.$data.pack('N', crc32($tipe.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 16, 16, 8, 0, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
