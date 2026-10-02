<?php

namespace Bale\Srikandi\Listeners;

use Bale\Srikandi\Models\SrikandiOtpState;
use Bale\Srikandi\Support\OtpCode;
use Bale\Srikandi\Support\OtpPhone;
use Bale\Wara\Events\WaraIncomingMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Mencocokkan kode OTP dari balasan operator masuk (spec §6.1).
 *
 * 🔴 Yang TIDAK dilakukan listener ini: mengarang record OTP baru.
 *
 * Kalau tidak ada record `pending` untuk nomor pengirim, pesan itu adalah hal
 * biasa — balasan chat, spam, atau apa pun — dan harus diabaikan. Membuat
 * record di sini akan berarti `bale/srikandi` menebak-nebak kapan pesan
 * incoming adalah OTP, padahal keputusan itu milik scraper yang tahu konteks
 * login Srikandinya (spec §1).
 *
 * 🔴 Yang TIDAK dilakukan juga: memverifikasi JENDELA LISTENING (kontrak v2
 * §5.0b). Jendela itu ada supaya scraper bisa MEMBACA kode Srikandi lewat
 * `GET /otp-pending`. Bale tidak pernah tahu kode itu — tidak ada yang bisa
 * dicocokkan di sini, dan memaksanya berarti Bale mulai menebak mana OTP,
 * persis yang dilarang §1.
 */
class HandleIncomingMessage
{
    /**
     * Satu-satunya event yang boleh dicocokkan dengan OTP (spec §6.1).
     */
    protected const EVENT_MESSAGE = 'message';

    public function __construct(protected OtpPhone $phones) {}

    public function handle(WaraIncomingMessage $event): void
    {
        // 🔴 Listener memeriksa sendiri jenis eventnya, dan tidak bergantung
        // pada-axis pengirim. `WebhookController` memang hanya dispatch untuk
        // `message` hari ini, tapi kalau itu satu-satunya penjaga, setiap
        // dispatcher baru harus mengingat aturan yang sama. Kalau tidak,
        // `message.ack` yang kebetulan membawa `body` akan ikut memverifikasi.
        if ($event->event !== self::EVENT_MESSAGE) {
            return;
        }

        if (! $event->isAddressableOneToOne()) {
            return;
        }

        $phone = $this->phones->normalizeOrNull($event->phone);
        $body = $event->body ?? '';

        if ($phone === null) {
            return;
        }

        $expectedLength = (int) config('srikandi.otp.code_length', 6);

        // Buang sebelum query. Chat biasa hampir tidak pernah berisi angka
        // sepanjang kode OTP, jadi ini menghemat satu query untuk sebagian
        // besar pesan masuk.
        if (OtpCode::digitCount($body) !== $expectedLength) {
            return;
        }

        $states = $this->matchableStates($phone);

        if ($states->isEmpty()) {
            return;
        }

        foreach ($states as $state) {
            if ($this->matches($state, $body)) {
                $this->markVerified($state, $event);

                return;
            }
        }

        /*
         * Tidak ada yang cocok -> biarkan `pending` (spec §6.1).
         *
         * Sengaja TIDAK menaikkan `attempts`. Aturan itu milik endpoint
         * `otp-verify` yang dipanggil scraper; pesan masuk yang salah ketik
         * bukan percobaan verifikasi, dan menghitungnya di sini akan
         * menghabiskan jatah percobaan orang tanpa interaksinya.
         */
    }

    /**
     * Cari record OTP yang masih hidup dan **punya kode** untuk dicocokkan.
     *
     * 🔴 `whereNotNull('code_hash')` itu wajib, bukan sekadar pengoptimalan.
     *
     * Jendela listening (kontrak v2 §5.0b) tidak punya kode: `code_hash`-nya
     * NULL karena kode OTP-nya datang dari Srikandi, bukan dari Bale. Tanpa
     * filter ini, jendela ikut terbawa ke dalam loop dan `OtpCode::verify()`
     * akan membandingkan pesan inbound dengan string kosong — selalu gagal, tapi
     * dengan biaya `Hash::check()` untuk tiap record.
     *
     * Yang lebih penting: filter ini membuat "jendela tidak diverifikasi
     * listener" menjadi sifat yang ditegakkan database, bukan sekadar Georgian
     * yang mudah hilang saat refactor.
     *
     * @return Collection<int, SrikandiOtpState>
     */
    protected function matchableStates(string $phone)
    {
        return SrikandiOtpState::query()
            ->where('phone', $phone)
            ->where('state', SrikandiOtpState::STATE_PENDING)
            ->where('expires_at', '>', now())
            ->whereNotNull('code_hash')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Cocokkan isi pesan dengan kode yang tersimpan.
     *
     * Normalisasi ada di dalam {@see OtpCode}, jadi `123 456` dan `123-456`
     * tetap dikenali sebagai kode yang sama (spec §6.1).
     */
    protected function matches(SrikandiOtpState $state, string $body): bool
    {
        return OtpCode::verify($body, (string) $state->getAttribute('code_hash'));
    }

    protected function markVerified(SrikandiOtpState $state, WaraIncomingMessage $event): void
    {
        $state->forceFill([
            'state' => SrikandiOtpState::STATE_VERIFIED,
            'verified_at' => $state->verified_at ?? now(),
        ])->save();

        Log::info('Srikandi: kode OTP terverifikasi dari balasan WhatsApp.', [
            'state_id' => $state->id,
            'purpose' => $state->purpose,
            'session_key' => $state->session_key,
            'device_id' => $event->deviceId,
        ]);

        /*
         * 🔴 Yang dicatat di sini TIDAK BOLEH memuat isi pesan.
         *
         * `code_hash` dan `body` sengaja tidak masuk. Log biasanya dibaca orang
         * yang tidak boleh tahu isi OTP, dan `code_hash` di log berarti isi kode
         * tinggal diambil dari sana (spec §7).
         */
    }
}
