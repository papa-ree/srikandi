<?php

namespace Bale\Srikandi\Services;

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Notifications\NaskahBaruDiterima;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\WaraManager;
use Throwable;

/**
 * Kirim uji coba notifikasi dari form client (tombol "Test kirim").
 *
 * 🔴 KENAPA KELAS TERSENDIRI, DAN KENAPA TIDAK LEWAT ANTREAN.
 *
 * Jalur produksi mengirim lewat antrean (`NaskahBaruDiterima` implements
 * `ShouldQueue`) supaya siklus scraping tidak ikut menunggu gateway. Tombol uji
 * justru sebaliknya: admin menekan tombol dan harus tahu SEKARANG berhasil atau
 * tidak, beserta alasannya kalau gagal. Karena itu pengiriman di sini memanggil
 * `WaraManager` langsung, bukan `Notification::route()->notify()`.
 *
 * Yang diuji tetap jalur yang sama dengan produksi - normalisasi nomor, resolusi
 * device lewat `wara_routes`, dan gateway. Yang berbeda hanya penjadwalannya.
 *
 * 🔴 TANGKAP `Throwable`, JANGAN `WaraException`.
 *
 * Kegagalan bisa datang dari `bale/wara` (`WaraException`) ATAU dari konfigurasi
 * wara yang belum siap (`DeviceNotReadyException`, `GatewayException`), dan
 * semuanya diwarisi dari `Exception`. Satu kegagalan tidak boleh menggagalkan
 * seluruh fan-out: kalau penerima kedua tidak punya nomor valid, penerima ketiga
 * tetap dicoba dan hasilnya dilaporkan per nomor.
 */
class NotificationTester
{
    public function __construct(protected WaraManager $wara) {}

    /**
     * Uji notifikasi OTP gagal/kedaluwarsa (S5.1) ke Nomor Akun.
     *
     * 🔴 Nomor Akun, BUKAN penerima naskah. Dua peran itu sengaja dipisah:
     * nomor akun menerima OTP dari Srikandi dan pemberitahuan bila OTP gagal
     * atau kedaluwarsa; notifikasi naskah baru punya daftar penerima sendiri.
     *
     * @return array{ok: bool, sent: list<string>, failed: list<array{nomor: string, alasan: string}>}
     */
    public function sendOtp(
        SrikandiClient $client,
        string $phone,
        ?string $purpose,
        ?WaraClient $waraClient,
    ): array {
        $message = sprintf(
            "[UJI] Notifikasi OTP %s\n\nPesan percobaan. Nomor akun ini menerima kode OTP dari Srikandi dan pemberitahuan bila OTP gagal atau kedaluwarsa.",
            $client->nama,
        );

        return $this->send([$phone], $message, $purpose, $waraClient);
    }

    /**
     * Uji notifikasi naskah baru (S5.2) ke daftar penerima aktif.
     *
     * Pesan dirakit dari kelas notifikasi produksi ({@see NaskahBaruDiterima})
     * supaya yang diuji benar-benar teks yang sama dengan yang dikirim produksi.
     *
     * @param  list<string>  $recipients  nomor penerima, sudah ternormalisasi
     * @return array{ok: bool, sent: list<string>, failed: list<array{nomor: string, alasan: string}>}
     */
    public function sendNaskah(
        SrikandiClient $client,
        array $recipients,
        ?string $purpose,
        ?WaraClient $waraClient,
        int $jumlah = 1,
    ): array {
        $message = (new NaskahBaruDiterima($client->nama, $jumlah))->toWara();

        return $this->send($recipients, $message, $purpose, $waraClient);
    }

    /**
     * @param  list<string>  $destinations
     * @return array{ok: bool, sent: list<string>, failed: list<array{nomor: string, alasan: string}>}
     */
    protected function send(
        array $destinations,
        string $message,
        ?string $purpose,
        ?WaraClient $waraClient,
    ): array {
        $purpose = filled($purpose) ? $purpose : null;

        $sent = [];
        $failed = [];

        foreach (array_values(array_unique($destinations)) as $destination) {
            try {
                $this->wara->sendMessage(
                    $destination,
                    $message,
                    purpose: $purpose,
                    client: $waraClient,
                );

                $sent[] = $destination;
            } catch (Throwable $e) {
                $failed[] = ['nomor' => $destination, 'alasan' => $e->getMessage()];
            }
        }

        return [
            'ok' => $failed === [] && $sent !== [],
            'sent' => $sent,
            'failed' => $failed,
        ];
    }
}
