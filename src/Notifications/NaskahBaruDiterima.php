<?php

namespace Bale\Srikandi\Notifications;

use Bale\Srikandi\Services\NaskahIngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

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
 * Pesan dirakit lewat `toWara()`; `WaraChannel` membacanya. Nomor tujuan datang
 * dari `notify_phone` milik client (route anonymous `Notification::route('wara',
 * ...)`), bukan ditulis di kelas ini — supaya satu kelas notifikasi tidak
 * terikat pada satu nomor.
 */
class NaskahBaruDiterima extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $namaClient,
        public int $jumlah,
    ) {}

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
     */
    public function toWara($notifiable = null): string
    {
        return sprintf(
            "%s: %d naskah baru belum dibaca.\n\nBuka Bale untuk menindaklanjuti.",
            $this->namaClient,
            $this->jumlah,
        );
    }
}
