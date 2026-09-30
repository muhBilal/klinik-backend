<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Jadwal (dijalankan `php artisan schedule:work`, proses `scheduler` di supervisor / docker-compose.dev).
| Tugas terjadwal fitur berikutnya (reminder WhatsApp, kirim ulang SATUSEHAT) ditambahkan di sini.
*/

// Token login yang sudah kedaluwarsa > 24 jam dihapus dari database
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();

// Job gagal disimpan 7 hari untuk investigasi, lalu dibersihkan
Schedule::command('queue:prune-failed --hours=168')->daily()->onOneServer();
