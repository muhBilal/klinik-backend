<?php

namespace App\Services\WhatsApp;

/** Pengirim pesan template WhatsApp. Implementasi: LogGateway (dev) & CloudGateway (WhatsApp Cloud API Meta). */
interface WhatsAppGateway
{
    /**
     * @param  list<string>  $parameter  isi {{1}}, {{2}}, ... badan template
     * @param  list<string>  $tombol  payload tombol balasan cepat sesuai urutan di template
     * @return string id pesan dari penyedia
     */
    public function kirimTemplate(string $ke, string $template, array $parameter, array $tombol = []): string;
}
