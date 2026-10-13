<?php

namespace Bale\Srikandi\Notifications;

use Bale\Srikandi\Services\NaskahIngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Notifikasi "ada naskah baru" ke admin (spec §7, PLAN S5.2).
 *
 * 🔴 YANG MEMUTUSKAN "BARU" ADALAH BALE, BUKAN SCRAPER.
 *
 * Kelas ini hanya MEMBAWA pesan. Sumber `jumlah`-nya adalah `inserted` yang
 * dihitung {@see NaskahIngestService} dari
 * perbandingan `row_hash` — deteksi `rak-srikandi` (heartbeat) tidak dipakai
 * sebagai penentu. Lihat PRD §7.
 *
 * 🔴 `ShouldQueue` DISENGAJA.
 *
 * Pemicunya ada di dalam request scraper (`POST /naskah-dinas`). Mengirim
 * WhatsApp sinkron di situ berarti siklus scraping ikut menunggu gateway —
 * dan kegagalan gateway bisa memperlambat/menggagalkan ingest yang sebenarnya
 * sudah sukses. Antrean memisahkan keduanya.
 *
 * Pesan dirakit lewat `toWara()`; `WaraChannel` membacanya. Tujuan datang dari
 * daftar penerima aktif milik client (`srikandi_notify_recipients`, dikirim
 * `NaskahNotifier` lewat route anonymous `Notification::route('wara', ...)`),
 * bukan ditulis di kelas ini — supaya satu kelas notifikasi tidak terikat pada
 * satu nomor.
 *
 * 🔴 DAFTAR NASKAH DIBEKUKAN SAAT AKUMULASI, BUKAN DICARI SAAT KIRIM.
 *
 * `$naskah` berisi baris compact (`nomor`, `hal`, `pengirim`, `tanggal`) yang
 * disalin `NaskahIngestService` dari baris yang BENAR-BENAR baru masuk di
 * siklus ini (`inserted`). Calon alternatif — query `srikandi_naskah` dengan
 * `first_seen_at > batas siklus` — sengaja tidak dipakai: `notify_baseline_at`
 * bukan penanda siklus (dia jangkar sekali jalan untuk redam), dan mencarinya
 * saat kirim berarti pesan bisa berubah kalau baris direvisi di tengah siklus.
 * Baris hanya untuk TAMPILAN; keputusan "baru" tetap punya Bale
 * (`row_hash`).
 */
class NaskahBaruDiterima extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{nomor: string, hal: string|null, pengirim: string|null, tanggal: string|null}>  $naskah
     */
    public function __construct(
        public string $namaClient,
        public int $jumlah,
        public array $naskah = [],
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['wara'];
    }

    /**
     * Pesan teks untuk `WaraChannel` (lihat `WaraChannel::resolveMessage()`).
     *
     * Tidak ada suffix JID dan tidak ada nomor pengirim: channel yang tahu
     * tujuan, `WaraManager` yang menormalkan, `DeviceRouter` yang memilih device.
     *
     * Setiap naskah ditampilkan sebagai blok baris-bawah (baris kebawah):
     * nomor urut + `hal`, lalu `Pengirim:` dan `Tanggal:` di baris berikutnya.
     * Kolom yang nilainya tidak terbaca di payload diganti placeholder —
     * baris yang ada tetap jujur, tidak disembunyikan.
     */
    public function toWara($notifiable = null): string
    {
        $out = sprintf(
            '%s: %d naskah baru belum dibaca.',
            $this->namaClient,
            $this->jumlah,
        );

        $baris = [];

        foreach ($this->naskah as $i => $row) {
            $baris[] = sprintf(
                "%d. %s\n   Pengirim: %s\n   Tanggal: %s",
                $i + 1,
                $this->teks($row['hal'] ?? null, '(tanpa hal)'),
                $this->teks($row['pengirim'] ?? null, '(tanpa pengirim)'),
                $this->teks($this->formatTanggal($row['tanggal'] ?? null), '(tanpa tanggal)'),
            );
        }

        if ($baris !== []) {
            $out .= "\n\n" . implode("\n", $baris);
        }

        return $out . "\n\nBuka Aplikasi Srikandi untuk menindaklanjuti.";
    }

    /**
     * Nilai kolom atau placeholder kalau kosong.
     */
    protected function teks(mixed $value, string $fallback): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $fallback;
    }

    /**
     * `Y-m-d` yang sudah dinormalisasi → bentuk yang nyaman dibaca
     * (mis. `01 Okt 2026`). NULL/tidak terbaca → NULL, bukan tanggal sampah.
     */
    protected function formatTanggal(mixed $tanggal): ?string
    {
        if (!is_string($tanggal) || trim($tanggal) === '') {
            return null;
        }

        $parsed = Carbon::parse(trim($tanggal));

        return $parsed->format('d M Y');
    }
}
