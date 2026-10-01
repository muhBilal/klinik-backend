<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImporMasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Impor master ICD-10, ICD-9-CM & obat dari CSV (PRD v2 AD-10). */
class ImporMasterController extends Controller
{
    public function __invoke(Request $request, string $jenis, ImporMasterService $service): JsonResponse
    {
        abort_unless(in_array($jenis, ImporMasterService::JENIS, true), 404);
        $data = $request->validate(['berkas' => ['required', 'file', 'max:20480', 'mimes:csv,txt']]);

        return response()->json($service->impor($jenis, $data['berkas']->getRealPath()));
    }
}
