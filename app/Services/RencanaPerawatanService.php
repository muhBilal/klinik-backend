<?php

namespace App\Services;

use App\Enums\StatusItemRencana;
use App\Enums\StatusRencanaPerawatan;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\Pasien;
use App\Models\RencanaPerawatan;
use App\Models\RencanaPerawatanItem;
use App\Models\Tindakan;
use App\Models\User;
use App\Support\CabangAktif;
use App\Support\Gigi;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rencana perawatan gigi (PRD DG-02): item per gigi dikelompokkan per fase dengan estimasi biaya.
 *
 * Alur: draf (item bebas diubah) → disetujui pasien (estimasi dikunci; ubah = revisi kembali ke draf) → item dikerjakan
 * lewat tindakan kunjungan (`rencana_item_id`) dan menjadi selesai saat kunjungan ditutup → rencana selesai otomatis bila
 * tidak ada item tersisa. Estimasi memakai harga cabang penyusun saat item disusun; tagihan tetap memakai harga saat dikerjakan.
 */
class RencanaPerawatanService
{
    public const RELASI = [
        'dokter:id,name,sip',
        'cabang:id,kode,nama,alamat,telepon',
        'penyetuju:id,name',
        'pembatal:id,name',
        'kunjungan:id,tanggal,no_registrasi',
        'items.tindakan:id,kode,nama,per_gigi,kondisi_gigi_hasil,template_consent_id,jenis_catatan,protokol_foto_id,icd9cm_id',
        'items.tindakan.icd9cm:id,kode,nama',
        'items.pelaksanaan:id,kunjungan_id,rencana_item_id',
        'items.pelaksanaan.kunjungan:id,tanggal,no_registrasi,status',
    ];

    public function __construct(private CabangAktif $cabang) {}

    /** @param  array{judul: string, catatan?: ?string, kunjungan_id?: ?int, dokter_id?: ?int, items: list<array>}  $data */
    public function buat(Pasien $pasien, array $data, User $user): RencanaPerawatan
    {
        $kunjungan = isset($data['kunjungan_id'])
            ? Kunjungan::where('pasien_id', $pasien->id)->findOrFail($data['kunjungan_id'])
            : null;

        return DB::transaction(function () use ($pasien, $data, $user, $kunjungan) {
            $rencana = RencanaPerawatan::create([
                'pasien_id' => $pasien->id,
                'cabang_id' => $kunjungan?->cabang_id ?? $this->cabang->untukDataBaru(),
                'kunjungan_id' => $kunjungan?->id,
                'dokter_id' => $data['dokter_id'] ?? ($user->tercatatSebagaiDokter() ? $user->id : $kunjungan?->dokter_id),
                'judul' => $data['judul'],
                'catatan' => $data['catatan'] ?? null,
                'status' => StatusRencanaPerawatan::Draf,
                'created_by' => $user->id,
            ]);

            $this->simpanItems($rencana, $data['items']);

            return $this->muat($rencana);
        });
    }

    public function ubah(RencanaPerawatan $rencana, array $data): RencanaPerawatan
    {
        $this->pastikanCabang($rencana);

        if ($rencana->status !== StatusRencanaPerawatan::Draf) {
            throw ValidationException::withMessages(['status' => $rencana->status === StatusRencanaPerawatan::Disetujui
                ? 'Rencana sudah disetujui pasien. Buka revisi dulu (persetujuan perlu diulang).'
                : 'Rencana yang sudah selesai atau dibatalkan tidak dapat diubah.']);
        }

        return DB::transaction(function () use ($rencana, $data) {
            $rencana->update(Arr::only($data, ['judul', 'catatan', 'dokter_id']));
            $this->simpanItems($rencana, $data['items']);

            return $this->muat($rencana);
        });
    }

    /** Pasien/wali menyetujui rencana & estimasi biaya. */
    public function setujui(RencanaPerawatan $rencana, ?string $penyetuju, User $user): RencanaPerawatan
    {
        $this->pastikanCabang($rencana);

        if ($rencana->status !== StatusRencanaPerawatan::Draf) {
            throw ValidationException::withMessages(['status' => 'Hanya rencana berstatus draf yang dapat disetujui.']);
        }
        if ($rencana->items()->where('status', StatusItemRencana::Rencana)->doesntExist()) {
            throw ValidationException::withMessages(['items' => 'Rencana belum berisi item yang akan dikerjakan.']);
        }

        $rencana->update([
            'status' => StatusRencanaPerawatan::Disetujui,
            'disetujui_at' => now(),
            'disetujui_oleh' => $user->id,
            'penyetuju_nama' => $penyetuju ?: $rencana->pasien->nama,
        ]);

        return $this->muat($rencana);
    }

    /** Kembalikan rencana yang sudah disetujui ke draf agar bisa diubah; persetujuan dihapus dan harus diulang. */
    public function revisi(RencanaPerawatan $rencana): RencanaPerawatan
    {
        $this->pastikanCabang($rencana);

        if ($rencana->status !== StatusRencanaPerawatan::Disetujui) {
            throw ValidationException::withMessages(['status' => 'Hanya rencana yang sudah disetujui yang perlu direvisi.']);
        }

        $rencana->update(['status' => StatusRencanaPerawatan::Draf, 'disetujui_at' => null, 'disetujui_oleh' => null, 'penyetuju_nama' => null]);

        return $this->muat($rencana);
    }

    public function batal(RencanaPerawatan $rencana, string $alasan, User $user): RencanaPerawatan
    {
        $this->pastikanCabang($rencana);

        if (! $rencana->status->bisaDikerjakan()) {
            throw ValidationException::withMessages(['status' => 'Rencana sudah selesai atau dibatalkan.']);
        }
        if ($dikerjakan = $this->sedangDikerjakan($rencana->items()->pluck('id')->all())) {
            throw ValidationException::withMessages(['status' => "Ada item yang sedang dikerjakan di kunjungan {$dikerjakan}. Hapus dari tindakan kunjungan itu dulu."]);
        }

        return DB::transaction(function () use ($rencana, $alasan, $user) {
            $rencana->items()->where('status', StatusItemRencana::Rencana)->get()->each->update(['status' => StatusItemRencana::Batal]);
            $rencana->update([
                'status' => StatusRencanaPerawatan::Dibatalkan,
                'dibatalkan_at' => now(),
                'dibatalkan_oleh' => $user->id,
                'alasan_batal' => $alasan,
            ]);

            return $this->muat($rencana);
        });
    }

    /**
     * Item rencana yang dikerjakan tindakan kunjungan: milik pasien kunjungan, rencananya masih berjalan, belum selesai,
     * dan tidak sedang dikerjakan tindakan lain.
     */
    public function pastikanBisaDikerjakan(int $itemId, Kunjungan $kunjungan, ?int $kunjunganTindakanId, string $kunci): RencanaPerawatanItem
    {
        $item = RencanaPerawatanItem::with('rencana')->find($itemId);

        $pesan = match (true) {
            ! $item || (int) $item->rencana->pasien_id !== (int) $kunjungan->pasien_id => 'Item rencana perawatan tidak ditemukan untuk pasien ini.',
            ! $item->rencana->status->bisaDikerjakan() => 'Rencana perawatan sudah selesai atau dibatalkan.',
            $item->status !== StatusItemRencana::Rencana => 'Item rencana perawatan ini sudah selesai atau dibatalkan.',
            KunjunganTindakan::where('rencana_item_id', $itemId)->when($kunjunganTindakanId, fn ($q) => $q->whereKeyNot($kunjunganTindakanId))->exists() => 'Item rencana perawatan ini sedang dikerjakan di tindakan lain.',
            default => null,
        };

        if ($pesan) {
            throw ValidationException::withMessages([$kunci => $pesan]);
        }

        return $item;
    }

    /** Kunjungan ditutup & ditandatangani: item yang dikerjakan menjadi selesai; rencana tanpa item tersisa selesai. */
    public function selesaikanDariKunjungan(Kunjungan $kunjungan): void
    {
        $items = RencanaPerawatanItem::whereIn('id', $kunjungan->tindakans()->whereNotNull('rencana_item_id')->pluck('rencana_item_id'))
            ->where('status', StatusItemRencana::Rencana)
            ->get();

        foreach ($items as $item) {
            $item->update(['status' => StatusItemRencana::Selesai, 'selesai_at' => now()]);
        }

        foreach (RencanaPerawatan::whereIn('id', $items->pluck('rencana_perawatan_id')->unique())->get() as $rencana) {
            if ($rencana->status->bisaDikerjakan() && $rencana->items()->where('status', StatusItemRencana::Rencana)->doesntExist()) {
                $rencana->update(['status' => StatusRencanaPerawatan::Selesai, 'selesai_at' => now()]);
            }
        }
    }

    /** Muat relasi + ringkasan estimasi (per fase, total, sudah dikerjakan). */
    public function muat(RencanaPerawatan $rencana): RencanaPerawatan
    {
        $rencana->load(self::RELASI);

        return $this->ringkas($rencana);
    }

    public function ringkas(RencanaPerawatan $rencana): RencanaPerawatan
    {
        $berlaku = $rencana->items->where('status', '!=', StatusItemRencana::Batal);

        $rencana->setAttribute('estimasi_total', $berlaku->sum(fn ($i) => $i->tarif * $i->jumlah));
        $rencana->setAttribute('estimasi_selesai', $berlaku->where('status', StatusItemRencana::Selesai)->sum(fn ($i) => $i->tarif * $i->jumlah));
        $rencana->setAttribute('estimasi_per_fase', $berlaku->groupBy('fase')->map(fn ($items, $fase) => [
            'fase' => (int) $fase,
            'total' => $items->sum(fn ($i) => $i->tarif * $i->jumlah),
            'jumlah_item' => $items->count(),
        ])->sortBy('fase')->values());

        return $rencana;
    }

    /**
     * Upsert item: cocok lewat `id`. Item selesai tidak bisa diubah/dihapus; item baru atau berganti treatment memakai
     * harga cabang rencana saat ini sebagai estimasi.
     */
    private function simpanItems(RencanaPerawatan $rencana, array $items): void
    {
        $master = Tindakan::withTrashed()->whereIn('id', Arr::pluck($items, 'tindakan_id'))
            ->select(['id', 'nama', 'tarif', 'per_gigi', 'is_active', 'deleted_at'])
            ->denganHargaCabang($rencana->cabang_id)
            ->get()
            ->keyBy('id');
        $lama = $rencana->items()->get()->keyBy('id');

        foreach ($items as $i => $item) {
            if (isset($item['id']) && ! $lama->has($item['id'])) {
                throw ValidationException::withMessages(["items.{$i}.id" => 'Item tidak termasuk rencana ini.']);
            }

            $tindakan = $master[$item['tindakan_id']];
            $sama = isset($item['id']) && (int) $lama[$item['id']]->tindakan_id === $tindakan->id;
            if (! $sama && (! $tindakan->is_active || $tindakan->trashed())) {
                throw ValidationException::withMessages(["items.{$i}.tindakan_id" => "{$tindakan->nama} sudah tidak aktif."]);
            }
            if (! $sama && ! $tindakan->tersedia) {
                throw ValidationException::withMessages(["items.{$i}.tindakan_id" => "{$tindakan->nama} tidak dilayani di cabang ini."]);
            }
            if ($tindakan->per_gigi && empty($item['gigi'])) {
                throw ValidationException::withMessages(["items.{$i}.gigi" => "Pilih nomor gigi untuk {$tindakan->nama}."]);
            }
        }

        $dikirim = collect($items)->pluck('id')->filter()->all();
        $dihapus = $lama->except($dikirim);

        if ($dihapus->contains(fn ($item) => $item->status === StatusItemRencana::Selesai)) {
            throw ValidationException::withMessages(['items' => 'Item yang sudah dikerjakan tidak dapat dihapus dari rencana.']);
        }
        if ($kunjungan = $this->sedangDikerjakan($dihapus->keys()->all())) {
            throw ValidationException::withMessages(['items' => "Item yang sedang dikerjakan di kunjungan {$kunjungan} tidak dapat dihapus."]);
        }

        $dihapus->each->delete();

        foreach ($items as $urutan => $item) {
            $baris = isset($item['id']) ? $lama[$item['id']] : null;
            if ($baris?->status === StatusItemRencana::Selesai) {
                $baris->update(['urutan' => $urutan]);

                continue;
            }

            $tindakan = $master[$item['tindakan_id']];
            $atribut = [
                'fase' => $item['fase'] ?? 1,
                'urutan' => $urutan,
                'gigi' => $item['gigi'] ?? null,
                'permukaan' => Gigi::normalPermukaan($item['permukaan'] ?? null),
                'tindakan_id' => $tindakan->id,
                'jumlah' => $item['jumlah'] ?? 1,
                'keterangan' => $item['keterangan'] ?? null,
            ];

            if ($baris) {
                if ((int) $baris->tindakan_id !== $tindakan->id) {
                    $atribut['tarif'] = $tindakan->tarif_cabang;
                }
                $baris->update($atribut);
            } else {
                $rencana->items()->create([...$atribut, 'tarif' => $tindakan->tarif_cabang, 'status' => StatusItemRencana::Rencana]);
            }
        }
    }

    /** No. registrasi kunjungan terbuka yang sedang mengerjakan salah satu item; null bila tidak ada. */
    private function sedangDikerjakan(array $itemIds): ?string
    {
        if (! $itemIds) {
            return null;
        }

        return KunjunganTindakan::whereIn('rencana_item_id', $itemIds)
            ->whereHas('kunjungan', fn ($q) => $q->whereIn('status', ['menunggu', 'diperiksa']))
            ->with('kunjungan:id,no_registrasi')
            ->first()?->kunjungan?->no_registrasi;
    }

    private function pastikanCabang(RencanaPerawatan $rencana): void
    {
        $aktif = $this->cabang->id();

        if ($aktif && $rencana->cabang_id && (int) $rencana->cabang_id !== (int) $aktif) {
            throw ValidationException::withMessages(['cabang' => 'Rencana perawatan ini disusun di cabang lain; ubah dari cabang tersebut.']);
        }
    }
}
