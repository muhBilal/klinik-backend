<?php

use App\Models\Berkas;
use App\Services\ImporMasterService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Impor master resmi dari CSV (PRD v2 AD-10): php artisan eklinik:impor icd10 storage/app/icd10.csv
Artisan::command('eklinik:impor {jenis : icd10 | icd9cm | obat} {berkas : path CSV}', function (string $jenis, string $berkas) {
    if (! in_array($jenis, ImporMasterService::JENIS, true) || ! is_file($berkas)) {
        $this->error('Jenis harus icd10/icd9cm/obat dan berkas harus ada.');

        return 1;
    }

    $hasil = app(ImporMasterService::class)->impor($jenis, $berkas);
    $this->info("Baru {$hasil['baru']}, diperbarui {$hasil['diperbarui']}, sama {$hasil['sama']}, galat ".count($hasil['galat']));
    foreach ($hasil['galat'] as $g) {
        $this->warn("Baris {$g['baris']}: {$g['pesan']}");
    }

    return 0;
})->purpose('Impor master ICD-10 / ICD-9-CM / obat dari CSV (idempoten, tidak menghapus kode)');

// Data uji beban (PRD v2 7.2: pencarian < 1 detik pada 100.000 pasien). Ditolak di production.
Artisan::command('eklinik:data-uji {--pasien=100000 : jumlah pasien uji}', function () {
    if (app()->environment('production')) {
        $this->error('Tidak boleh dijalankan di production.');

        return 1;
    }

    $depan = ['Siti', 'Ahmad', 'Dewi', 'Budi', 'Rina', 'Agus', 'Putri', 'Eko', 'Nur', 'Dian', 'Andi', 'Sri', 'Yusuf', 'Maya', 'Rudi', 'Lestari'];
    $belakang = ['Wijaya', 'Santoso', 'Lestari', 'Pratama', 'Hidayat', 'Saputra', 'Kusuma', 'Rahmawati', 'Nugroho', 'Wulandari', 'Setiawan', 'Permata'];
    $total = (int) $this->option('pasien');
    $bar = $this->output->createProgressBar($total);
    $awal = (int) (DB::table('pasiens')->max('id') ?? 0);

    for ($i = 0; $i < $total; $i += 1000) {
        $baris = [];
        for ($j = $i; $j < min($i + 1000, $total); $j++) {
            $n = $awal + $j + 1;
            $hp = '08'.str_pad((string) (1000000000 + $n), 10, '0', STR_PAD_LEFT);
            $baris[] = [
                'no_rm' => 'U'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
                'nik' => '9'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
                'nama' => $depan[$n % count($depan)].' '.$belakang[intdiv($n, 16) % count($belakang)].' '.$n,
                'jenis_kelamin' => $n % 2 ? 'P' : 'L',
                'tanggal_lahir' => now()->subDays(6000 + $n % 20000)->toDateString(),
                'no_hp' => $hp, 'no_hp_digit' => $hp,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('pasiens')->insert($baris);
        $bar->advance(count($baris));
    }
    $bar->finish();
    $this->newLine();
    $this->info("{$total} pasien uji ditambahkan.");

    return 0;
})->purpose('Tambah pasien uji untuk uji beban (bukan production)');

// Enkripsi ulang nilai terenkripsi dengan APP_KEY terbaru (PRD v2 7.2 keamanan).
// Alur rotasi: simpan kunci baru di APP_KEY, kunci lama di APP_PREVIOUS_KEYS (biar dekripsi lama tetap jalan),
// jalankan perintah ini, lalu kunci lama boleh dihapus dari APP_PREVIOUS_KEYS.
Artisan::command('eklinik:enkripsi-ulang {--dry : hitung saja, tidak menulis}', function () {
    $dry = (bool) $this->option('dry');
    if (empty(config('app.previous_keys'))) {
        // Tanpa kunci lama, nilai yang masih dienkripsi dengan kunci lama tak bisa didekripsi;
        // baris seperti itu dilaporkan "gagal" dan dilewati (tidak ada data yang ditulis rusak).
        $this->warn('APP_PREVIOUS_KEYS kosong — nilai yang masih memakai kunci lama akan dilewati.');
    }

    // Kolom ber-cast `encrypted` / `encrypted:array`. Nilai mentah = Crypt::encryptString(payload),
    // jadi re-wrap generik (decrypt lalu encrypt) aman untuk skalar maupun array.
    $kolom = [
        'informed_consents' => ['ttd_penandatangan', 'ttd_saksi'],
        'persetujuan_datas' => ['ttd'],
        'persetujuan_fotos' => ['ttd'],
        'users' => ['two_factor_secret', 'two_factor_recovery_codes'],
    ];
    $ok = 0;
    $gagal = 0;
    foreach ($kolom as $tabel => $kols) {
        foreach ($kols as $kol) {
            DB::table($tabel)->whereNotNull($kol)->select('id', $kol)->orderBy('id')
                ->chunkById(500, function ($rows) use ($tabel, $kol, $dry, &$ok, &$gagal) {
                    foreach ($rows as $r) {
                        try {
                            $baru = Crypt::encryptString(Crypt::decryptString($r->$kol));
                            if (! $dry) {
                                DB::table($tabel)->where('id', $r->id)->update([$kol => $baru]);
                            }
                            $ok++;
                        } catch (Throwable $e) {
                            $gagal++;
                            $this->warn("{$tabel}#{$r->id}.{$kol}: {$e->getMessage()}");
                        }
                    }
                });
        }
    }

    // Isi berkas terenkripsi di disk (foto klinis, lampiran, tanda tangan consent).
    $disk = Storage::disk('berkas');
    foreach (Berkas::query()->get(['id', 'path', 'thumbnail_path']) as $b) {
        foreach (array_filter([$b->path, $b->thumbnail_path]) as $path) {
            try {
                if (! $disk->exists($path)) {
                    continue;
                }
                $isi = Crypt::encryptString(Crypt::decryptString($disk->get($path)));
                if (! $dry) {
                    $disk->put($path, $isi);
                }
                $ok++;
            } catch (Throwable $e) {
                $gagal++;
                $this->warn("berkas#{$b->id} {$path}: {$e->getMessage()}");
            }
        }
    }

    $this->info(($dry ? '[dry] ' : '')."Dienkripsi ulang {$ok}, gagal {$gagal}.");

    return $gagal ? 1 : 0;
})->purpose('Enkripsi ulang nilai terenkripsi dengan APP_KEY terbaru setelah rotasi kunci');

/*
| Jadwal (dijalankan `php artisan schedule:work`, proses `scheduler` di supervisor / docker-compose.dev).
| Tugas terjadwal fitur berikutnya (reminder WhatsApp, kirim ulang SATUSEHAT) ditambahkan di sini.
*/

// Detak scheduler (PRD v2 7.2 observabilitas): halaman Integrasi → Sistem memperingatkan bila scheduler berhenti
Schedule::call(fn () => Cache::put('eklinik.scheduler.detak', now()->toIso8601String(), now()->addDay()))
    ->name('detak-scheduler')->everyMinute()->onOneServer();

// WhatsApp (BK-06, CR-01): buat reminder H-1 / 2 jam & follow-up H+1 / H+7 yang jatuh tempo (tanpa efek bila nonaktif)
Artisan::command('eklinik:wa-jadwal', function () {
    $this->info('Pesan baru: '.app(WhatsAppService::class)->jadwalkan());
})->purpose('Jadwalkan pesan WhatsApp otomatis yang jatuh tempo');
Schedule::command('eklinik:wa-jadwal')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// Token login yang sudah kedaluwarsa > 24 jam dihapus dari database
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();

// Job gagal disimpan 7 hari untuk investigasi, lalu dibersihkan
Schedule::command('queue:prune-failed --hours=168')->daily()->onOneServer();
