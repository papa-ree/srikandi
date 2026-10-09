<?php

use Bale\Srikandi\Models\SrikandiOtpState;
use Bale\Wara\Models\WaraLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

// `seedIncoming()` pindah ke `helpers.php`. Semula dideklarasikan di file ini
// saja, padahal itu fungsi global -- file test lain yang membutuhkannya
// bergantung pada urutan include. `helpers.php` memang tempat fungsi
// bersama, dan `OtpClientIsolationTest` butuh fungsi yang sama untuk
// membuktikan jendela client lain benar-benar tidak terlihat.

describe('GET /otp-pending (spec §5.2)', function () {
    it('mengembalikan balasan untuk record pending', function () {
        seedPendingOtp('628123456789', '123456');
        seedIncoming('628999888777', 'kode OTP 123456');

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('replies.0.body', 'kode OTP 123456');
    });

    it('mengembalikan array kosong bila tidak ada balasan', function () {
        seedPendingOtp('628123456789', '123456');

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertOk()
            ->assertJsonPath('replies', []);
    });

    /*
         * 🔴 TES ARAH. Ini yang mengunci bug yang diperbaiki 1 Okt 2026.
         *
         * Filter lama menyaring `chat_id IN (<nomor device sendiri>)`. `chat_id`
         * adalah nomor PENGIRIM, jadi filter itu secara efektif mencari pesan yang
         * DIKIRIM ke nomor kita - yaitu pesan KELUAR milik device ini sendiri, bukan
         * OTP yang masuk. Arahnya tepat terbalik: OTP dari Srikandi punya `chat_id`
         * = nomor Srikandi, jadi tidak pernah bisa cocok.
         *
         * Gejalanya bukan "balasan yang salah". Gejalanya `replies: []` selamanya,
         * berapa kali pun scraper melakukan polling.
         */
    it('TIDAK mengembalikan pesan KELUAR ke nomor device sendiri', function () {
        seedPendingOtp('628123456789', '123456');

        // Pesan yang DIKIRIM device ini ke nomor itself: chat_id = nomor kita.
        // Filter lama akan mengembalikannya.
        WaraLog::query()->create([
            'direction' => 'out',
            'event' => 'message',
            'device_id' => otpTestDeviceId(),
            'chat_id' => '628123456789@s.whatsapp.net',
            'phone' => '628123456789',
            'body' => '111111',
            'status' => 'accepted',
        ]);

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertOk()
            ->assertJsonPath('replies', []);
    });

    it('mengembalikan pesan masuk device dari nomor pengirim yang berbeda', function () {
        seedPendingOtp('628123456789', '123456');

        // Bentuk nyata OTP: pengirim = Srikandi, penerima = device kita.
        seedIncoming('628111111111', 'kode OTP 123456');

        $response = $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper());

        expect($response->json('replies'))->toHaveCount(1)
            ->and($response->json('replies.0.body'))->toBe('kode OTP 123456');
    });

    it('TIDAK membaca pesan milik device lain, walaupun pengirimnya sama', function () {
        seedPendingOtp('628123456789', '123456');

        // Pengirim identik, tapi masuk ke device LAIN. Melewati sini berarti
        // OTP milik satker lain bocor ke jendela ini.
        seedIncoming('628111111111', '999999', null, 'dev-lain');
        seedIncoming('628111111111', '111111', null, otpTestDeviceId());

        $response = $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper());

        expect($response->json('replies'))->toHaveCount(1)
            ->and($response->json('replies.0.body'))->toBe('111111');
    });

    it('jendela tanpa device mengembalikan kosong, bukan pesan semua orang', function () {
        // Baris lama sebelum kontrak v2: `session_key` NULL.
        seedPendingOtp('628123456789', '123456', ['session_key' => null]);

        seedIncoming('628111111111', '111111');
        seedIncoming('628111111111', '999999', null, 'dev-lain');

        // `where('device_id', null)` akan cocok dengan semua baris yang device-nya
        // tidak diketahui. Itu mengembalikan pesan milik device lain.
        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertOk()
            ->assertJsonPath('replies', []);
    });

    it('jendela membaca lewat request_id, bukan phone', function () {
        $state = seedPendingOtp('628123456789', '123456');
        seedIncoming('628111111111', 'kode OTP 123456');

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&request_id='.$state->request_id, asScraper())
            ->assertOk()
            ->assertJsonPath('replies.0.body', 'kode OTP 123456');
    });

    /*
     * 🔴 TES FORMAT PENGENAL DEVICE.
     *
     * `bale/wara` menulis `wara_logs.device_id` dengan DUA nilai berbeda:
     *   - `WaraManager::recordOutgoing()` -> `$session->device_id` (NAMA device)
     *   - `WebhookController::recordIncoming()` -> nilai dari GOWA (JID)
     *
     * Terverifikasi 2 Okt 2026 terhadap 266 pesan sungguhan: semua baris
     * inbound memakai JID. Kalau polling hanya mencocokkan nama device, filter
     * ini tidak akan pernah menemukan lalu lintas nyata - gejalanya `replies: []`
     * selamanya, sama seperti bug yang asli.
     */
    it('membaca pesan yang device_id-nya JID, bukan nama device', function () {
        $state = seedPendingOtp('628123456789', '123456');

        seedIncoming('628111111111', 'kode OTP dari JID 123456', null, '628123456789@s.whatsapp.net');

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&request_id='.$state->request_id, asScraper())
            ->assertOk()
            ->assertJsonPath('replies.0.body', 'kode OTP dari JID 123456');
    });

    it('membaca pesan yang device_id-nya nama device (format outbound)', function () {
        $state = seedPendingOtp('628123456789', '123456');

        seedIncoming('628111111111', 'kode OTP dari NAMA 123456', null, otpTestDeviceId());

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&request_id='.$state->request_id, asScraper())
            ->assertOk()
            ->assertJsonPath('replies.0.body', 'kode OTP dari NAMA 123456');
    });

    it('JID milik device lain tidak boleh bocor meski nomornya mirip', function () {
        seedPendingOtp('628123456789', '123456');

        // Bedanya hanya pada sufiks JID - bentuk yang dipakai gateway.
        seedIncoming('628111111111', '111111', null, '628123456788@s.whatsapp.net');

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertOk()
            ->assertJsonPath('replies', []);
    });

    it('`after` mencegah pembacaan ulang pesan lama', function () {
        $state = seedPendingOtp('628123456789', '123456');

        $first = seedIncoming('628123456789', '111111');

        $this->travel(1)->minutes();

        seedIncoming('628123456789', '222222');

        // Tanpa `after`: dua balasan.
        $all = $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper());
        expect($all->json('replies'))->toHaveCount(2);

        // Dengan `after` tepat setelah pesan pertama: hanya yang kedua.
        $later = $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789&after='
            .urlencode($first->created_at->toIso8601String()), asScraper());

        expect($later->json('replies'))->toHaveCount(1)
            ->and($later->json('replies.0.body'))->toBe('222222');
    });

    it('TIDAK membocorkan balasan bila record tidak pending', function () {
        $state = seedPendingOtp('628123456789', '123456', [
            'state' => SrikandiOtpState::STATE_EXPIRED,
        ]);

        seedIncoming('628123456789', '123456');

        // Gate state=pending: pesan lama tidak boleh bocor lewat endpoint ini.
        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertOk()
            ->assertJsonPath('replies', []);
    });

    it('TIDAK membocorkan balasan untuk record yang sudah terverifikasi', function () {
        seedPendingOtp('628123456789', '123456', [
            'state' => SrikandiOtpState::STATE_VERIFIED,
            'verified_at' => now(),
        ]);

        seedIncoming('628123456789', '123456');

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertJsonPath('replies', []);
    });

    it('TIDAK membocorkan balasan record yang sudah lewat masa berlaku', function () {
        seedPendingOtp('628123456789', '123456', ['expires_at' => now()->subMinute()]);

        seedIncoming('628123456789', '123456');

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertJsonPath('replies', []);
    });

    it('hanya membaca pesan yang masuk ke device jendela', function () {
        seedPendingOtp('628123456789', '123456');

        seedIncoming('628111111111', '999999', null, 'dev-lain');
        seedIncoming('628222222222', '888888', null, 'dev-lain');
        seedIncoming('628111111111', '123456', null, otpTestDeviceId());

        $response = $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper());

        expect($response->json('replies'))->toHaveCount(1)
            ->and($response->json('replies.0.body'))->toBe('123456');
    });

    it('mengabaikan pesan keluar (direction=out)', function () {
        seedPendingOtp('628123456789', '123456');

        WaraLog::query()->create([
            'direction' => 'out',
            'event' => 'message',
            'device_id' => otpTestDeviceId(),
            'phone' => '628123456789',
            'body' => '123456',
            'status' => 'accepted',
        ]);

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertJsonPath('replies', []);
    });

    it('menormalkan nomor lokal untuk mencari jendela', function () {
        // Normalisasi tetap berlaku untuk MENCARI jendela (jalur `phone`), bukan
        // untuk menyaring pesan. Jadi test ini masih valid - hanyaTUJUAN filter
        // yang berubah.
        seedPendingOtp('628123456789', '123456');
        seedIncoming('628111111111', '123456');

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=08123456789', asScraper())
            ->assertOk()
            ->assertJsonPath('replies.0.body', '123456');
    });

    it('menolak tanpa identitas', function () {
        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default', asScraper())->assertStatus(422);
    });

    it('membatasi jumlah balasan per pembacaan', function () {
        seedPendingOtp('628123456789', '123456');

        foreach (range(1, 30) as $i) {
            seedIncoming('628111111111', '1'.$i.'23456');
        }

        $response = $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper());

        expect($response->json('replies'))->toHaveCount(20);
    });

    it('mengembalikan message_id untuk korelasi scraper', function () {
        seedPendingOtp('628123456789', '123456');
        seedIncoming('628111111111', 'kode 123456', 'msg-abc');

        $this->getJson('/api/v1/srikandi/otp-pending?sumber=default&phone=628123456789', asScraper())
            ->assertJsonPath('replies.0.message_id', 'msg-abc');
    });
});
