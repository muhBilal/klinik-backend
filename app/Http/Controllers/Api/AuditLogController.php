<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audit log read-only (PRD AD-03). Tidak ada endpoint ubah/hapus.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
            'user_id' => ['nullable', 'integer'],
            'pasien_id' => ['nullable', 'integer'],
            'cabang_id' => ['nullable', 'integer'],
            'subjek_id' => ['nullable', 'integer'],
        ]);

        $logs = AuditLog::query()
            ->select(['id', 'user_id', 'cabang_id', 'aksi', 'tipe', 'subjek_id', 'pasien_id', 'label', 'ip_address', 'created_at'])
            ->with(['user:id,name,email', 'cabang:id,kode,nama'])
            ->when($request->filled('aksi'), fn ($q) => $q->whereIn('aksi', explode(',', $request->input('aksi'))))
            ->when($request->filled('tipe'), fn ($q) => $q->where('tipe', $request->input('tipe')))
            ->when($request->filled('subjek_id'), fn ($q) => $q->where('subjek_id', $request->integer('subjek_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('pasien_id'), fn ($q) => $q->where('pasien_id', $request->integer('pasien_id')))
            ->when($request->filled('cabang_id'), fn ($q) => $q->where('cabang_id', $request->integer('cabang_id')))
            ->when($request->filled('dari'), fn ($q) => $q->where('created_at', '>=', $request->date('dari')->startOfDay()))
            ->when($request->filled('sampai'), fn ($q) => $q->where('created_at', '<=', $request->date('sampai')->endOfDay()))
            ->when($request->filled('q'), fn ($q) => $q->whereLike('label', '%'.$request->string('q')->trim().'%'))
            ->latest('id');

        return response()->json($this->paginate($logs, $request, 30));
    }

    /** Detail satu baris termasuk rincian perubahan kolom. */
    public function show(AuditLog $auditLog): JsonResponse
    {
        return response()->json($auditLog->load(['user:id,name,email', 'cabang:id,kode,nama']));
    }
}
