<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Paginasi list. `?simple=1` (dipakai autocomplete) melewati query COUNT(*) sehingga lebih ringan.
     */
    protected function paginate(Builder $query, Request $request, int $perPage = 20, int $max = 100): Paginator
    {
        $perPage = min(max($request->integer('per_page', $perPage), 1), $max);

        return $request->boolean('simple') ? $query->simplePaginate($perPage) : $query->paginate($perPage);
    }

    /**
     * Filter `?status=aktif|nonaktif` untuk master data yang punya kolom `is_active`.
     */
    protected function filterAktif(Builder $query, Request $request): Builder
    {
        $status = $request->input('status');

        return $query->when(in_array($status, ['aktif', 'nonaktif'], true), fn ($q) => $q->where('is_active', $status === 'aktif'));
    }
}
