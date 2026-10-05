<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Support\ErrorSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Endpoint client Srikandi (S3).
 *
 * 🔴 TIGA HAL YANG BEDA DARI ENDPOINT LAIN DI PACKAGE INI
 *
 * 1. **Membaca kredensial.** `GET /clients/{slug}/credentials` mengembalikan
 *    password, TOTP secret, dan Gemini API key dalam plaintext. Ini satu-satunya
 *    tempat di seluruh sistem yang melepas kredensial keluar database, dan itu
 *    karena alasan yang benar: scraper butuh kredensial untuk login, dan cara
 *    yang lebih aman daripada memberi kredensial ke scraper adalah memusatkannya
 *    di satu tempat yang bisa dirotasi.
 *
 * 2. **403, bukan 404, untuk client yang nonaktif.** Client nonaktif itu masih
 *    ada. Menyamarkannya jadi 404 membuat "kenapa client saya hilang" mustahil
 *    didiagnosis, karena scraper akan menyimpulkan slug-nya salah lalu mencoba
 *    slug lain.
 *
 * 3. **Audit setiap akses kredensial.** Bukan karena kredensialnya sensitif
 *    (memang, tapi itu sudah tertutup scope), tapi karena ini satu-satunya cara
 *    menjawab "kredensial client ini dibaca siapa dan kapan".
 */
class ClientController extends SrikandiController
{
    /**
     * `GET /clients` — daftar client untuk tear-down dan pemilihan account.
     *
     * 🔴 TIDAK memuat kredensial, TIDAK memuat nomor tujuan, TIDAK memuat index.
     *
     * Yang dikembalikan cukup untuk scraper memutuskan "akun mana yang mau
     * dijalankan": slug, nama, aktif atau tidak, dan apakah sudah siap (sudah
     * punya username/password/nomor). Sisanya hanya noise — dan `phone` terenkripsi
     * berarti membacanya di sini hanya menambah satu tempat kredensial bisa
     * bocor tanpa perlu.
     */
    public function index(): JsonResponse
    {
        $clients = SrikandiClient::query()
            ->orderBy('slug')
            ->get()
            ->map(fn (SrikandiClient $client) => [
                'slug' => $client->slug,
                'nama' => $client->nama,
                'is_active' => $client->isActive(),
                // `ready` bukan `is_active`: client aktif tapi belum diisi
                // kredensialnya akan gagal login dengan 401 lalu sia-sia
                // mencoba captcha. Scraper perlu tahu itu sebelum mulai.
                'ready' => $client->isReadyForScraper(),
                'last_login_at' => $client->last_login_at?->toIso8601String(),
                'last_error_at' => $client->last_error_at?->toIso8601String(),
            ]);

        return $this->ok(['clients' => $clients]);
    }

    /**
     * `GET /clients/{slug}/credentials` — kredensial login satu client.
     *
     * 🔴 TIDAK memuat `phone`. Nomor tujuan itu urusan Bale: scraper tidak perlu
     * tahu ke mana OTP dikirim, dan memuatnya berarti kredensial yang
     * dikembalikan jadi lebih dari yang dibutuhkan.
     */
    public function credentials(Request $request, string $slug): JsonResponse
    {
        try {
            $client = $this->resolveClient($slug);
        } catch (SrikandiException $e) {
            return $this->failure($e);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }

        $this->auditCredentialRead($request, $client);

        return $this->ok([
            'client' => [
                'slug' => $client->slug,
                'username' => $client->username,
                'password' => $client->password,
                'totp_secret' => $client->totp_secret,
                'totp_digits' => $client->totp_digits,
                'totp_period' => $client->totp_period,
                'gemini_api_key' => $client->gemini_api_key,
                'gemini_model' => $client->gemini_model,
                'gemini_fallback_model' => $client->gemini_fallback_model,
                'credentials_rotated_at' => $client->credentials_rotated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * `POST /clients/{slug}/heartbeat` — laporan hasil satu siklus scraper.
     *
     * 🔴 Isinya bukan data, tapi waktu. Gunanya supaya Bale bisa menampilkan
     * "client ini login 3 menit lalu" tanpa mengira scraper masih hidup hanya
     * karena tidak ada error.
     */
    public function heartbeat(Request $request, string $slug): JsonResponse
    {
        // 🔴 `credentials_rotated_at` SENGAJA TIDAK ada di daftar ini.
        //
        // Kalau scraper boleh melaporkannya, satu siklus dengan urutan salah
        // bisa membuat Bale menampilkan banner "kredensial diganti" padahal
        // tidak ada yang diganti — dan scraper sendiri akan terus diberi tahu
        // untuk login ulang.
        $validated = $request->validate([
            'login_at' => ['nullable', 'date'],
            'naskah_at' => ['nullable', 'date'],
            'error_at' => ['nullable', 'date'],
            'error_message' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $client = $this->resolveClient($slug);
        } catch (SrikandiException $e) {
            return $this->failure($e);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }

        // 🔴 `error_message` DISANITASI SEBELUM DISIMPAN.
        //
        // Tanpa ini, satu pesan error Playwright yang memuat URL dengan
        // kredensial di query string akan menuliskan password Srikandi ke
        // `last_error_message` -- kolom `text` yang tidak terenkripsi dan dibaca
        // dari halaman UI.
        //
        // Sanitasi ini best-effort, bukan jaminan: kredensial dalam bentuk lain
        // (base64, terpecah) tidak akan tertangkap. Aturan aslinya tetap "jangan
        // kirim kredensial di pesan error" -- lihat `ErrorSanitizer`.
        $client->forceFill(array_filter([
            'last_login_at' => $validated['login_at'] ?? null,
            'last_naskah_at' => $validated['naskah_at'] ?? null,
            'last_error_at' => $validated['error_at'] ?? null,
            'last_error_message' => (new ErrorSanitizer)->sanitizeAndTruncate(
                $validated['error_message'] ?? null
            ),
        ], fn ($value) => $value !== null))->save();

        return $this->ok([
            // 🔴 BALE yang menentukan "kredensial belum pernah dicoba", bukan
            // scraper. Scraper membandingkan `credentials_rotated_at` dengan
            // `last_login_at` yang dilaporkan barusan — kalau rotasi lebih baru,
            // berarti ada kredensial yang belum pernah dicoba, dan itu yang
            // memunculkan banner tanpa perlu ada push dari Bale.
            'credentials_rotated_at' => $client->credentials_rotated_at?->toIso8601String(),
            'last_login_at' => $client->last_login_at?->toIso8601String(),
            'needs_login' => $client->credentials_rotated_at !== null
                && ($client->last_login_at === null
                    || $client->credentials_rotated_at->gt($client->last_login_at)),
        ]);
    }

    /**
     * Cari client yang aktif, atau gagal dengan pesan yang jelas.
     *
     * 🔴 Client nonaktif = 403, bukan 404. Lihat catatan factory
     * {@see SrikandiException::clientInactive()} untuk alasannya.
     */
    protected function resolveClient(string $slug): SrikandiClient
    {
        $client = SrikandiClient::query()->where('slug', $slug)->first();

        if ($client === null) {
            throw SrikandiException::notFound("Client '{$slug}' tidak ada.");
        }

        if (! $client->isActive()) {
            throw SrikandiException::clientInactive(
                "Client '{$slug}' sedang nonaktif. Aktifkan di UI sebelum scraper berjalan.",
                ['slug' => $slug]
            );
        }

        return $client;
    }

    /**
     * Catat setiap pembacaan kredensial.
     *
     * 🔴 Sengaja memakai `Activity` langsung, BUKAN trait `LogsActivity` model.
     *
     * Trait itu menulis nilai kolom yang berubah ke `attribute_changes` — dan
     * di sini "nilai kolom" adalah kredensial plaintext. Kalau entri audit ini
     * dibuat lewat trait, `activity_log` akan jadi tempat paling mudah dibaca
     * siapa pun yang punya akses database, karena di situ ada password semua
     * client.
     *
     * Yang ditulis di sini cuma empat field: slug, token, IP, user agent.
     */
    protected function auditCredentialRead(Request $request, SrikandiClient $client): void
    {
        $token = $request->user('api-token');

        Activity::query()->create([
            'log_name' => 'srikandi.credentials',
            'description' => sprintf(
                'Kredensial client %s dibaca (token %s)',
                $client->slug,
                $token === null ? 'tanpa token' : (string) $token->getKey()
            ),
            'subject_type' => SrikandiClient::class,
            'subject_id' => $client->getKey(),
            'properties' => [
                'slug' => $client->slug,
                'token_id' => $token?->getKey(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);
    }
}
