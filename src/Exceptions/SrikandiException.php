<?php

namespace Bale\Srikandi\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Galat yang sudah diklasifikasi: setiap instance membawa status HTTP.
 *
 * Scraper membaca `message` dari body untuk memutuskan tindakan berikutnya, jadi
 * pesannya harus bisa dibedakan secara operasional — "kode salah" dan "kode
 * kedaluwarsa" menghasilkan tindakan yang berbeda (spec §5.3).
 */
class SrikandiException extends RuntimeException
{
    protected function __construct(
        string $message,
        protected int $status = 400,
        protected string $errorCode = 'srikandi_error',
        protected array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidRequest(string $message, array $context = []): self
    {
        return new self($message, 422, 'invalid_request', $context);
    }

    public static function notFound(string $message, array $context = []): self
    {
        return new self($message, 404, 'not_found', $context);
    }

    /**
     * Percobaan di luar masa berlaku → 410.
     *
     * 410, bukan 422 dan bukan 401: kode masih "benar" secara sintaks, tapi
     * tidak akan pernah berlaku lagi. Scraper yang membedakan ini bisa
     * langsung meminta kode baru, sedangkan yang tidak akan membuang percobaan
     * login Srikandi untuk kode yang sudah tak berguna.
     */
    public static function otpExpired(array $context = []): self
    {
        return new self(
            'Kode OTP sudah kedaluwarsa. Minta kode baru.',
            410,
            'otp_expired',
            $context,
        );
    }

    public static function tooManyAttempts(string $message, array $context = []): self
    {
        return new self($message, 429, 'too_many_attempts', $context);
    }

    /**
     * OTP tidak bisa dikirim (device offline, cooldown, dll).
     *
     * Kodenya tetap 422 supaya scraper tidak salah menganggap ini throttle.
     */
    public static function otpDeliveryFailed(string $message, array $context = []): self
    {
        return new self($message, 422, 'otp_delivery_failed', $context);
    }

    /**
     * Tidak ada device `purpose` yang siap.
     *
     * 🔴 Ini kondisi yang harus diperbaiki admin, bukan kegagalan transien. Jadi
     * pesannya menyebut device/purpose yang bermasalah — scraper tidak punya
     * nomor untuk dicoba, dan diam-diam memakai nomor default akan membaca kode
     * dari nomor yang tidak diminta.
     *
     * Status 422, bukan 503: yang perlu diperbaiki adalah konfigurasi, dan
     * mencoba ulang tanpa perubahan tidak akan menolong.
     */
    public static function noOtpDevice(string $message, array $context = []): self
    {
        return new self($message, 422, 'no_otp_device', $context);
    }

    /**
     * Verifikasi diminta untuk jendela listening yang tidak punya kode.
     *
     * 422 dengan kode error sendiri, supaya scraper membedakannya dari "kode
     * salah" dan TIDAK menghabiskan jatah percobaannya untuk request yang
     * memang tidak bisa berhasil.
     */
    public static function windowNotVerifiable(array $context = []): self
    {
        return new self(
            'Jendela listening tidak punya kode untuk diverifikasi. '
            .'Kode OTP berasal dari Srikandi; baca lewat GET /otp-pending.',
            422,
            'window_not_verifiable',
            $context,
        );
    }

    /**
     * Client ada tapi dimatikan: 403, bukan 404.
     *
     * 403 dengan sengaja, dan ini perbedaan yang operasional.
     *
     * Kalau client nonaktif disamarkan jadi 404, scraper akan menyimpulkan
     * slug-nya salah lalu mencoba slug lain -- lalu berhenti setelah mencoba
     * semua slug dan melapor "tidak ada client yang bisa jalan". Sementara itu
     * operator melihat tidak ada error sama sekali.
     *
     * 403 memberitahu dua hal sekaligus: slug-nya benar, dan masalahnya
     * konfigurasi. Itu yang membuat operator bisa langsung mengaktifkan client
     * tanpa membaca log.
     */
    public static function clientInactive(string $message, array $context = []): self
    {
        return new self($message, 403, 'client_inactive', $context);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }
}
