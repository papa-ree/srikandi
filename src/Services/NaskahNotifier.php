<?php

namespace Bale\Srikandi\Services;

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Notifications\NaskahBaruDiterima;
use Illuminate\Support\Facades\Notification;

/**
 * Notifikasi "naskah baru" teragregasi per siklus (spec §7, PLAN S5.2).
 *
 * 🔴 PEMUTUS ADA DI BALE.
 *
 * `$inserted` datang dari {@see NaskahIngestService}, hasil perbandingan
 * `row_hash` — bukan dari deteksi `rak-srikandi`. Heartbeat scraper
 * (`naskah_baru` / `naskah_revisi`) hanya sinyal observabilitas; kalau suatu saat
 * scraper salah hitung, notifikasi tidak ikut salah.
 *
 * 🔴 DUA FASE, DUA PEMANGGIL.
 *
 * - `naskahMasuk()` dipanggil setelah tiap `naskah-dinas` (bisa beberapa kali
 *   dalam satu siklus karena batas 500 item per payload). Ia hanya MENAMBAH
 *   `inserted` ke `notify_pending_count` — tidak pernah mengirim sendiri.
 * - `tutupSiklus()` dipanggil di `heartbeat`, yang menurut KONTRAK §3.4/§8
 *   datang PERSIS SEKALI per siklus (setelah `runOnce()`, sukses maupun gagal).
 *   Ia mengirim SATU pesan dengan jumlah total akumulasi, lalu me-reset
 *   counter. Ini yang membuat satu harvest yang dipecah menjadi beberapa
 *   POST tetap menghasilkan SATU notifikasi.
 *
 *   Asumsi ini penting: kalau suatu saat scraper mencicil heartbeat lebih dari
 *   sekali per siklus, flush akan terjadi lebih awal dan agregasi pecah. Kalau
 *   itu terjadi, pindahkan flush ke `phase = done` (KONTRAK §2.3) dan mulai
 *   memvalidasi `phase` di `ClientController::heartbeat()`.
 *
 * 🔴 DIJALANKAN DI DALAM `try/catch` PEMANGGIL.
 *
 * Kalau notifikasi gagal, ingest/heartbeat yang sudah sukses tidak boleh ikut
 * gagal. Kedua controller yang membungkus; kelas ini sendiri sengaja tidak
 * menyembunyikan `Throwable` supaya kegagalan tak terduga tetap terlihat di log.
 */
class NaskahNotifier
{
    /**
     * Fase 1 — akumulasi. Panggil tepat setelah `NaskahIngestService::ingest()`.
     *
     * Mencatat baseline kalau belum ada (siklus pertama yang benar-benar
     * memasukkan naskah), lalu menambahkan `inserted` ke `notify_pending_count`.
     */
    public function naskahMasuk(string $sumber, int $inserted): void
    {
        $client = SrikandiClient::query()->where('slug', $sumber)->first();

        if ($client === null) {
            return;
        }

        if ($inserted <= 0) {
            return;
        }

        if ($client->notify_baseline_at === null) {
            $client->forceFill(['notify_baseline_at' => now()])->save();
        }

        // `increment` menulis `col = col + inserted` di database — aman
        // terhadap read-modify-write kalau nanti ada dua POST paralel.
        $client->increment('notify_pending_count', $inserted);
    }

    /**
     * Fase 2 — flush di akhir siklus. Panggil dari `ClientController::heartbeat()`.
     *
     * Mengirim SATU notifikasi untuk semua naskah yang terakumulasi sejak flush
     * terakhir, lalu me-reset counter. Counter di-reset meskipun pengiriman
     * dibatalkan (redam baseline, gerbang mati): ini siklus, bukan request.
     */
    public function tutupSiklus(string $sumber): void
    {
        $client = SrikandiClient::query()->where('slug', $sumber)->first();

        if ($client === null) {
            return;
        }

        $pending = (int) $client->notify_pending_count;

        if ($pending <= 0) {
            return;
        }

        // Reset SELALU sebelum kirim: kalau `Notification::route(...)->notify()`
        // melempar (misal antrean mati), counter sudah nol jadi siklus ini tidak
        // akan terkirim/terkirim ulang di siklus berikutnya.
        $client->forceFill(['notify_pending_count' => 0])->save();

        if ($this->redamBaseline($client)) {
            return;
        }

        if (! $client->notify_enabled || $client->notify_via !== 'whatsapp') {
            return;
        }

        $this->kirimKePenerima($client, $pending);
    }

    /**
     * Kirim SATU notifikasi teragregasi ke SETIAP penerima aktif client.
     *
     * 🔴 Satu notifikasi per penerima, bukan satu untuk semua.
     *
     * `NaskahBaruDiterima` tidak menyimpan tujuan; tujuan ditentukan route
     * `Notification::route('wara', ...)`. Jadi penerima banyak = beberapa kali
     * `notify()` dengan objek notifikasi yang sama. Dedup berdasarkan nilai
     * ternormalisasi supaya satu nomor yang tercatat dua kali (tidak mungkin
     * lewat form, tapi mungkin lewat data lama) tidak dikirimi dua pesan.
     *
     * 🔴 TIDAK ADA fallback ke `phone`.
     *
     * Dulu `notify_phone` jatuh ke `phone` (nomor OTP) kalau kosong. Sekarang
     * penerima adalah SATU-SATUNYA sumber; client tanpa penerima aktif tidak
     * mengirim apa pun. Diam di sini lebih benar daripada mengirim nomor OTP ke
     * tempat yang tidak diminta admin.
     */
    private function kirimKePenerima(SrikandiClient $client, int $pending): void
    {
        $recipients = $client->notifyRecipients()
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $notification = new NaskahBaruDiterima($client->nama, $pending);
        $sudahDikirim = [];

        foreach ($recipients as $recipient) {
            $tujuan = (string) $recipient->value;

            if ($tujuan === '' || isset($sudahDikirim[$tujuan])) {
                continue;
            }

            $sudahDikirim[$tujuan] = true;

            Notification::route('wara', $tujuan)->notify($notification);
        }
    }

    /**
     * True kalau flush harus diredam karena masih dalam jendela redam baseline.
     *
     * Baseline dicatat di `naskahMasuk()` (siklus pertama yang memasukkan
     * naskah). Siklus pertama itu mengisi SEMUA naskah historis sekaligus, jadi
     * flush-nya diredam + counter tetap di-reset supaya angka siklus berikutnya
     * tidak tercampur angka pertama.
     */
    private function redamBaseline(SrikandiClient $client): bool
    {
        $baseline = $client->notify_baseline_at;

        if ($baseline === null) {
            return true;
        }

        $grace = (int) config('srikandi.notify.baseline_grace_minutes', 15);

        return $grace > 0 && $baseline->gt(now()->subMinutes($grace));
    }
}
