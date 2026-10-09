<?php

use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiOtpState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

/*
 * 🔴 Test ini menjawab satu pertanyaan: "apakah dua client bisa saling
 * membaca atau memakai jendela OTP client lain?"
 *
 * Srikandi Option A membuat `sumber` WAJIB dan menfilternya di setiap query,
 * jadi jawabannya harus "tidak" -- dan itu harus dibuktikan, bukan diasumsikan.
 *
 * Yang membuat test ini penting adalah KONDISINYA benar-benar sama. Dua client
 * sah bisa punya nomor telepon yang persis sama: nomor adalah milik operator,
 * bukan milik aplikasi. Kalau test memakai nomor berbeda untuk dua client,
 * filter `sumber` yang benar akan terlihat "lolos" walaupun filter itu tidak
 * ada. Karena itu hampir semua test di sini memakai SATU nomor yang sama
 * persis untuk kedua client.
 */

describe('sumber wajib ada di srikandi_clients', function () {
    it('otp-request menolak slug yang tidak dikenal dengan 422', function () {
        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'client-hantu',
            'phone' => '628123456789',
        ], asScraper())
            ->assertStatus(422)
            ->assertJsonValidationErrors('sumber');
    });

    it('otp-pending menolak slug yang tidak dikenal dengan 422', function () {
        $this->getJson('/api/v1/srikandi/otp-pending?phone=628123456789&sumber=client-hantu', asScraper())
            ->assertStatus(422)
            ->assertJsonValidationErrors('sumber');
    });

    it('otp-verify menolak slug yang tidak dikenal dengan 422', function () {
        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'client-hantu',
            'phone' => '628123456789',
            'code' => '123456',
        ], asScraper())
            ->assertStatus(422)
            ->assertJsonValidationErrors('sumber');
    });

    it('slug yang tidak dikenal tidak pernah membuat record OTP', function () {
        seedSendableDevice();

        $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'client-hantu',
            'phone' => '628123456789',
        ], asScraper())->assertStatus(422);

        // 422 harus berhenti di validasi. Kalau sempat masuk ke service, angka
        // ini naik dan ada record ownershipless di tabel.
        expect(SrikandiOtpState::query()->count())->toBe(0);
    });

    it('slug dengan format salah ditolak, bukan lolos ke cek database', function () {
        // Huruf besar dan underscore melanggar `regex:/^[a-z0-9-]+$/`. Slug
        // seperti ini tidak akan pernah ada di `srikandi_clients`, jadi
        // menambahkannya ke fixture hanya membuktikan kebetulan.
        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'Client_Salah_Format',
            'phone' => '628123456789',
            'code' => '123456',
        ], asScraper())
            ->assertStatus(422)
            ->assertJsonValidationErrors('sumber');
    });
});

describe('dua client, satu nomor yang sama persis', function () {
    beforeEach(function () {
        SrikandiClient::query()->create([
            'slug' => 'klien-dua',
            'nama' => 'Klien Dua',
        ]);
    });

    it('jendela OTP tiap client punya record sendiri', function () {
        /*
         * 🔴 Cooldown Wara dimatikan HANYA di test ini, dan itu bukan kebetulan.
         *
         * `SendPacer` menahan (bukan menolak) pengiriman kedua ke nomor yang
         * sama -- `waitForPhoneCooldown()` memang `usleep()` sampai
         * `wara.cooldown_seconds` habis, default 60 detik. Ini perilaku yang
         * BENAR: anti-ban WhatsApp bersifat per nomor TUJUAN, dan dua client
         * yang mengirim ke satu nomor memang satu burst untuk device itu.
         *
         * Efek sampingnya untuk Option A: dua client yang berbagi nomor saling
         * memperlambat. Itu Kopling KETERSEDIAAN, bukan kebocoran -- tidak ada
         * data yang tertukar, hanya satu panggilan yang menunggu. Mengubah
         * pacer jadi per-client justru melemahkan anti-ban, jadi itu keputusan
         * package wara, bukan srikandi.
         *
         * Test ini butuh dua pengiriman NYATA ke satu nomor, jadi cooldown
         * dimatikan agar tidak mengukur hal yang salah. Perilaku cooldown
         * sendiri sudah punya test di package wara.
         */
        config()->set('wara.cooldown_seconds', 0);

        seedSendableDevice();

        $first = $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'phone' => '628123456789',
        ], asScraper())->assertCreated();

        $second = $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'klien-dua',
            'phone' => '628123456789',
        ], asScraper())->assertCreated();

        // Dua record, satu nomor. Kalau hanya satu, client kedua diam-diam
        // memakai jendela client pertama dan tidak pernah menerima kodenya.
        expect(SrikandiOtpState::query()->count())->toBe(2)
            ->and($first->json('request_id'))->not->toBe($second->json('request_id'));

        expect(
            SrikandiOtpState::query()->where('sumber', 'default')->count()
        )->toBe(1)
            ->and(
                SrikandiOtpState::query()->where('sumber', 'klien-dua')->count()
            )->toBe(1);

        // Dua pengiriman benar-benar terjadi. Tanpa ini test bisa lulus walau
        // kode hanya terkirim sekali -- padahal itu justru kegagalan yang
        // palingsilent untuk client kedua.
        Http::assertSentCount(2);
    });

    it('otp-pending milik client lain tidak terlihat', function () {
        config()->set('wara.cooldown_seconds', 0);

        seedSendableDevice();

        $first = $this->postJson('/api/v1/srikandi/otp-request', [
            'sumber' => 'default',
            'phone' => '628123456789',
            // Wajib untuk polling: `otp-pending` membaca `device_id` dari
            // `session_key`. Tanpa itu endpoint sengaja mengembalikan
            // `replies: []`, dan test ini tidak bisa membedakan kebocoran dari
            // "tidak ada device yang memegang jendela".
            'session_key' => otpTestDeviceId(),
        ], asScraper())->assertCreated();

        // 🔴 Pesan WhatsApp harus disemai, kalau tidak test ini hampa.
        //
        // Tanpa pesan, `replies` kosong baik saat record client satu ditemukan
        // maupun saat tidak ditemukan -- dua kondisi yang justru berbeda
        // statusnya. Jadi `replies: []` tanpa pesan nyata akan lulus bahkan
        // kalau filter `sumber` dihapus sama sekali.
        seedIncoming('628123456789', 'kode OTP milik client satu 123456');

        // Sanity check: record-nya sendiri HARUS mengembalikan pesannya. Kalau
        // ini kosong, test di bawahnya lulus karena alasan yang salah.
        $this->getJson('/api/v1/srikandi/otp-pending?'.http_build_query([
            'sumber' => 'default',
            'request_id' => $first->json('request_id'),
        ]), asScraper())
            ->assertOk()
            ->assertJsonCount(1, 'replies');

        $this->getJson('/api/v1/srikandi/otp-pending?'.http_build_query([
            'sumber' => 'klien-dua',
            'request_id' => $first->json('request_id'),
        ]), asScraper())
            ->assertOk()
            ->assertJsonPath('replies', []);
    });

    it('otp-pending milik client lain tidak terlihat walau nomornya sama', function () {
        // Dipisah dari test di atas karena satu jenis kebocoran saja: di sini
        // `request_id`-nya milik client satu, tapi nomor yang dikirim adalah
        // nomor yang PUNYA record milik client satu. Kalau `findVerifiable()`
        // jatuh ke jalur nomor saat `request_id` tidak cocok, record itu
        // ditemukan dan pesannya bocor.
        $state = seedPendingOtp('628123456789', '123456', ['sumber' => 'default']);

        seedIncoming('628123456789', 'kode OTP milik client satu 123456');

        $this->getJson('/api/v1/srikandi/otp-pending?'.http_build_query([
            'sumber' => 'default',
            'request_id' => $state->request_id,
        ]), asScraper())
            ->assertOk()
            ->assertJsonCount(1, 'replies');

        $this->getJson('/api/v1/srikandi/otp-pending?'.http_build_query([
            'sumber' => 'klien-dua',
            'request_id' => $state->request_id,
            'phone' => '628123456789',
        ]), asScraper())
            ->assertOk()
            ->assertJsonPath('replies', []);
    });

    it('otp-pending lewat NOMOR tidak membaca jendela client lain', function () {
        $state = seedPendingOtp('628123456789', '123456', ['sumber' => 'default']);

        // Sama seperti di atas: tanpa pesan nyata, `replies: []` tidak
        // membuktikan apa pun.
        seedIncoming('628123456789', 'kode OTP milik client satu 123456');

        $this->getJson('/api/v1/srikandi/otp-pending?'.http_build_query([
            'sumber' => 'default',
            'phone' => '628123456789',
        ]), asScraper())
            ->assertOk()
            ->assertJsonCount(1, 'replies');

        // `request_id` sengaja tidak dikirim: yang diuji adalah jalur fallback
        // ke nomor, karena di sana nomor yang SAMA dimiliki kedua client.
        $this->getJson('/api/v1/srikandi/otp-pending?'.http_build_query([
            'sumber' => 'klien-dua',
            'phone' => '628123456789',
        ]), asScraper())
            ->assertOk()
            ->assertJsonPath('replies', []);

        expect($state->refresh()->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });

    it('otp-verify milik client lain tidak memverifikasi record client ini', function () {
        $state = seedPendingOtp('628123456789', '123456', ['sumber' => 'default']);

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'klien-dua',
            'phone' => '628123456789',
            'code' => '123456',
        ], asScraper())->assertStatus(404);

        // Ini yang paling penting: record milik `default` harus utuh. Kalau
        // bocor, `verified_at` terisi dan client satu kehilangan halamannya.
        $state->refresh();

        expect($state->state)->toBe(SrikandiOtpState::STATE_PENDING)
            ->and($state->verified_at)->toBeNull()
            ->and($state->attempts)->toBe(0);
    });

    it('request_id milik client lain tidak jatuh ke nomor yang sama', function () {
        $state = seedPendingOtp('628123456789', '123456', ['sumber' => 'default']);

        /*
         * 🔴 Kasus yang paling mudah lolos di implementasi.
         *
         * `request_id` milik `default` dikirim bersama `sumber` = `klien-dua`.
         * Karena nomor yang diberikan PUNYA record `default` yang masih hidup,
         * implementasi yang "coba request_id dulu, lalu fallback ke nomor"
         * akan mengembalikan record itu -- dan klien dua membaca isi jendela
         * client satu. Nomor sengaja dikirim di sini justru untuk membuat
         * fallback itu mungkin terjadi.
         */
        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'klien-dua',
            'phone' => '628123456789',
            'request_id' => $state->request_id,
            'code' => '123456',
        ], asScraper())->assertStatus(404);

        $state->refresh();

        expect($state->state)->toBe(SrikandiOtpState::STATE_PENDING)
            ->and($state->verified_at)->toBeNull()
            ->and($state->attempts)->toBe(0);
    });

    it('kode salah milik client lain TIDAK menghabiskan attempts client ini', function () {
        config()->set('srikandi.otp.max_attempts', 3);

        $state = seedPendingOtp('628123456789', '123456', ['sumber' => 'default']);

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'klien-dua',
            'phone' => '628123456789',
            'code' => '999999',
        ], asScraper())->assertStatus(404);

        // Kalau lock tidak difilter sumber, `attempts` client satu naik dari
        // client lain -- artinya satu token bisa mengunci jendela yang bukan
        // miliknya hanya dengan menebak.
        expect($state->refresh()->attempts)->toBe(0)
            ->and($state->state)->toBe(SrikandiOtpState::STATE_PENDING);
    });

    it('masing-masing client bisa verifikasi jendela sendiri', function () {
        $first = seedPendingOtp('628123456789', '111111', ['sumber' => 'default']);
        $second = seedPendingOtp('628123456789', '222222', ['sumber' => 'klien-dua']);

        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'default',
            'phone' => '628123456789',
            'code' => '111111',
        ], asScraper())->assertOk()->assertJsonPath('state', 'verified');

        // Kode milik client lain harus tetap dipakai lewat slug-nya sendiri,
        // bukan lewat "kode yang kebetulan cocok".
        $this->postJson('/api/v1/srikandi/otp-verify', [
            'sumber' => 'klien-dua',
            'phone' => '628123456789',
            'code' => '222222',
        ], asScraper())->assertOk()->assertJsonPath('state', 'verified');

        expect($first->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED)
            ->and($second->refresh()->state)->toBe(SrikandiOtpState::STATE_VERIFIED);
    });
});
