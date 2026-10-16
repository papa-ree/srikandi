<?php

namespace Bale\Srikandi\Services;

use Bale\Srikandi\Models\SrikandiSessionSnapshot;
use Illuminate\Support\Carbon;

/**
 * Simpan snapshot detail panen satu sesi scraper (rak-srikandi PLAN 2026-10-14).
 *
 * 🔴 IDEMPOTEN lewat `(sumber, started_at, finished_at)`.
 *
 * Scraper mengirim snapshot dengan `try/catch` yang menelan error (non-fatal,
 * lihat `client-bale.ts`). Kalau request sampai tapi respons gagal dibaca,
 * siklus berikutnya bisa mengirim sesi yang SAMA. `updateOrCreate` membuat
 * kiriman kedua memperbarui baris yang sudah ada, bukan menambah baris kedua.
 *
 * 🔴 Waktu di-normalisasi ke timezone aplikasi SEBELUM disimpan.
 *
 * Scraper mengirim ISO dengan offset `+07:00`. Kalau dibiarkan apa adanya,
 * nilai tersimpan memakai wall-clock +07:00 sementara pembacaan memakai
 * timezone aplikasi -- selisih yang tidak terlihat sampai ada yang
 * membandingkan dua kolom waktu. Normalisasi di sini membuat simpan dan baca
 * memakai acuan yang sama.
 */
class SessionSnapshotService
{
    /**
     * @param  array<string, mixed>  $data  payload tervalidasi dari controller
     */
    public function store(string $sumber, array $data): SrikandiSessionSnapshot
    {
        $key = [
            'sumber' => $sumber,
            'started_at' => $this->toAppTimezone($data['started_at']),
            'finished_at' => $this->toAppTimezone($data['finished_at']),
        ];

        $values = [
            'status' => $data['status'],
            'error_code' => $data['error_code'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'login_at' => $this->nullableToAppTimezone($data['login_at'] ?? null),
            'naskah_at' => $this->nullableToAppTimezone($data['naskah_at'] ?? null),
            // Array `CycleSnapshot[]` apa adanya. Sengaja TIDAK di-reshape:
            // bentuknya bisa bertambah tanpa migrasi, dan ini murni laporan.
            'snapshots' => $data['snapshots'] ?? [],
        ];

        return SrikandiSessionSnapshot::query()->updateOrCreate($key, $values);
    }

    protected function toAppTimezone(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    protected function nullableToAppTimezone(?string $value): ?Carbon
    {
        return $value === null ? null : $this->toAppTimezone($value);
    }
}
