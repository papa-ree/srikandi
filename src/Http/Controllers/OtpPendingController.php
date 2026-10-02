<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Models\SrikandiOtpState;
use Bale\Srikandi\Services\OtpService;
use Bale\Wara\Models\WaraLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/v1/srikandi/otp-pending (spec §5.2, kontrak v2 §5.0b).
 *
 * 🔴 Endpoint ini membalas ISI KODE OTP ke scraper. Karena itu:
 *   - hanya record yang masih hidup (`pending` + belum kedaluwarsa) yang dibaca;
 *   - hanya pesan yang masuk SEJAK jendela dibuka (`opened_at`);
 *   - hanya pesan yang MASUK KE device jendela itu (`device_id`);
 *   - tidak ada filter lain yang bisa lebih longgar dari itu.
 *
 * Ini jalur polling yang direkomendasikan `wara/TOPIK.md` §6 — fan-out HTTP
 * sengaja tidak dipakai untuk OTP (`WARA_CONSUMER_SRIKANDI_ENABLED` dibiarkan
 * `false`).
 */
class OtpPendingController extends SrikandiController
{
    public function __construct(protected OtpService $otp) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:32'],
            'after' => ['nullable', 'date'],
        ]);

        $requestId = $this->blankToNull($validated['request_id'] ?? null);
        $rawPhone = $this->blankToNull($validated['phone'] ?? null);

        if ($requestId === null && $rawPhone === null) {
            return $this->failure(
                SrikandiException::invalidRequest('request_id atau phone wajib diisi.')
            );
        }

        /*
         * 🔴 Nomor tujuan diambil dari RECORD yang ditemukan, bukan dari request.
         *
         * Pada kontrak v2 scraper hanya memegang `request_id` dan tidak tahu
         * nomor WA sama sekali. Menjadikan nomor sebagai syarat akan memblokir
         * justru konsumen yang paling baru — atau, lebih buruk, mendorong
         * scraper menyimpan nomor itu supaya bisa memanggil endpoint ini. Itu
         * kebocoran yang justru dihapus oleh kontrak v2.
         */
        $state = $this->otp->findVerifiable($rawPhone, $requestId);

        // Gate: record harus hidup. Tanpa ini, jendela yang sudah `expired` atau
        // `verified` masih bisa membaca pesan lamanya.
        if ($state === null || ! $state->isVerifiable()) {
            return $this->ok(['replies' => []]);
        }

        /*
         * 🔴 Device mana yang membuka jendela ini.
         *
         * `session_key` menyimpan `device_id` dari `openWindow()`. Itu satu-
         * satunya kunci yang bisa menjawab "pesan mana yang MASUK ke device itu".
         *
         * `phone` sudah tidak cukup, dan lebih buruk dari tidak berguna:
         * menyaring dengan nomor kita sendiri hanya akan mencocokkan pesan yang
         * DIKIRIM OLEH device, bukan yang diterima. Pada chat 1:1, `chat_id`
         * adalah lawannya — untuk OTP yang datang dari Srikandi, itu adalah
         * nomor pengirim Srikandi, jadi filter lama tidak mungkin cocok.
         *
         * Ini bukan kegagalan yang samar: endpoint-nya secara struktural tidak
         * mungkin pernah mengembalikan OTP Srikandi, berapa kali pun scraper
         * melakukan polling.
         *
         * Terverifikasi terhadap lalu lintas GOWA sungguhan, 1 Okt 2026:
         *   in/message  device_id="wago prod"  chat_id=628000000001@s.whatsapp.net
         * `device_id` adalah PENERIMA (nilainya sama dengan
         * `wara_sessions.device_id`, yaitu `id` device menurut GOWA);
         * `chat_id` adalah PENGIRIM.
         *
         * Kalau `session_key` NULL (baris lama sebelum kontrak v2), balasannya
         * dikosongkan — bukan disaring dengan NULL, karena
         * `where('device_id', null)` akan cocok dengan semua baris yang
         * device-nya tidak diketahui, dan itu mengembalikan pesan milik orang.
         */
        $deviceId = $this->blankToNull($state->session_key);

        if ($deviceId === null) {
            Log::warning('Srikandi: jendela OTP tanpa device, polling dilewati.', [
                'state_id' => $state->id,
                'request_id' => $state->request_id,
            ]);

            return $this->ok(['replies' => []]);
        }

        /*
         * Batas bawah jendela.
         *
         * 🔴 `opened_at` inilah yang membunuh kode basi dari siklus sebelumnya.
         * Tanpa filter ini, scraper yang membuka jendela baru akan membaca ulang
         * OTP Srikandi milik percobaan yang sudah gagal — persis yang membuat ia
         * mengambil kode yang salah lalu gagal login.
         */
        $openedAt = $state->windowOpenedAt();

        $after = $this->blankToNull($validated['after'] ?? null);

        return $this->ok([
            'replies' => $this->repliesFor(
                $this->deviceKeysFor($state),
                $openedAt,
                $after !== null ? Carbon::parse($after) : null,
            ),
        ]);
    }

    /**
     * Semua bentuk pengenal yang mungkin dipakai gateway untuk device itu.
     *
     * 🔴 `wara_logs.device_id` TIDAK konsisten, dan itu bukan salah package ini.
     *
     * Di `bale/wara`, dua penulis memakai dua nilai berbeda untuk device yang
     * SAMA:
     *
     *   - `WaraManager::recordOutgoing()` menulis `$session->device_id`
     *     -> nama device, mis. `Aduan Agent`;
     *   - `WebhookController::recordIncoming()` menulis nilai yang dikirim GOWA
     *     -> JID, mis. `628000000001@s.whatsapp.net`.
     *
     * Terverifikasi 2 Okt 2026 terhadap 266 pesan sungguhan: SEMUA baris inbound
     * memakai JID, sementara `wara_sessions.device_id` berisi nama.
     *
     * Kalau hanya nama yang dicocokkan, filter ini tidak akan pernah menemukan
     * lalu lintas nyata - dan gejalanya persis seperti bug aslinya: `replies: []`
     * selamanya. Karena itu semua bentuk dicocokkan.
     *
     * Perbaikan yang benar adalah menormalkan `device_id` di `bale/wara` supaya
     * satu device punya satu pengenal. Itu masuk refactor router (Gelombang 2),
     * bukan di sini - endpoint ini tidak boleh menulis ke tabel milik wara.
     *
     * @return list<string>
     */
    protected function deviceKeysFor(SrikandiOtpState $state): array
    {
        $keys = array_filter([
            (string) $state->session_key,
            // Nomor tujuan disimpan polos; JID device lazy-nya punya bentuk ini.
            $state->phone ? $state->phone.'@s.whatsapp.net' : null,
            $state->phone ? $state->phone.'@c.us' : null,
            (string) $state->phone,
        ], fn ($v) => $v !== null && $v !== '');

        return array_values(array_unique($keys));
    }

    /**
     * Balasan masuk ke device tersebut, sejak batas bawah jendela.
     *
     * 🔴 Pencarian memakai `device_id`, BUKAN `chat_id` dan BUKAN `phone`.
     *
     * `device_id` adalah PENERIMA. `chat_id` adalah lawannya (PENGIRIM), jadi
     * menyaring `chat_id` dengan nomor device sendiri hanya menemukan pesan
     * yang dikirim device itu - bukan yang diterimanya. `wara_logs.phone`
     * juga tidak bisa dipakai: kolom itu terenkripsi (`encrypted` cast),
     * dan memfilternya selalu mengembalikan kosong tanpa error, jadi gejalanya
     * "polling tidak pernah menemukan balasan" tanpa jejak sama sekali.
     *
     * 🔴 Membaca model `bale/wara` di sini ITU BOLEH dan itu bukan pelanggaran
     * batas package. Yang dilarang `wara/PRD.md` §12.5 adalah `bale/srikandi`
     * MENGAMBIL KEPUTUSAN "ini OTP atau bukan" atas nama Bale — keputusan itu
     * tetap milik scraper yang tahu konteks login Srikandinya. Di sini isi pesan
     * dikembalikan apa adanya, tanpa disaring.
     *
     * 🔴 `opened_at` dan `after` JAUH BEDA dan tidak boleh disamakan.
     *
     * - `opened_at` memakai `>=`: pesan yang tiba tepat di detik jendela dibuka
     *   masih milik jendela ini.
     * - `after` memakai `>` (ketat): pemanggil berarti "setelah titik ini".
     *
     * Menyatukan keduanya jadi satu batas `max(...)` dengan satu operator akan
     * merusak salah satunya. Dengan `>=` untuk keduanya, `after` yang menunjuk
     * pesan pertama justru ikut mengembalikan pesan pertama itu lagi — dan
     * scraper memproses balasan yang sudah pernah dilihat.
     *
     * @return list<array{body: string, received_at: string|null, message_id: string|null}>
     */
    protected function repliesFor(array $deviceKeys, Carbon $openedAt, ?Carbon $after): array
    {
        if ($deviceKeys === []) {
            return [];
        }

        return WaraLog::query()
            ->incoming()
            ->where('event', 'message')
            ->whereIn('device_id', $deviceKeys)
            ->where('created_at', '>=', $openedAt)
            ->when(
                $after !== null,
                fn ($query) => $query->where('created_at', '>', $after)
            )
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->map(fn (WaraLog $log) => [
                'body' => (string) $log->body,
                'received_at' => $log->created_at?->toIso8601String(),
                'message_id' => $log->message_id,
            ])
            ->values()
            ->all();
    }

    protected function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
