<?php

/*
 | ---------------------------------------------------------------------------
 | Enkripsi `phone` pada `srikandi_otp_states`
 | ---------------------------------------------------------------------------
 |
 | 🔴 Test di file ini mengunci perubahan yang paling senyap di migrasi ini.
 |
 | Kolom `phone` berubah dari plaintext ke cast `encrypted`. Gejala kalau ada
 | pemanggil yang masih `where('phone', ...)`: query TIDAK error, hanya
 | mengembalikan nol baris. Untuk OTP, itu artinya "balasan tidak pernah sampai"
 | tanpa satu petunjuk pun -- dan test lama akan tetap hijau kalau yang menguji
 | hanya "tidak ada error".
 |
 */

use Bale\Srikandi\Models\SrikandiOtpState;
use Bale\Srikandi\Support\BlindIndex;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../helpers.php';

uses(RefreshDatabase::class)
    ->beforeEach(srikandiSetup());

function otpState(array $overrides = []): SrikandiOtpState
{
    return SrikandiOtpState::query()->create(array_merge([
        'phone' => '628123456789',
        'sumber' => 'default',
        'state' => SrikandiOtpState::STATE_PENDING,
        'expires_at' => now()->addMinutes(5),
        'opened_at' => now(),
    ], $overrides));
}

describe('phone terenkripsi di srikandi_otp_states', function () {
    it('menyimpan phone sebagai ciphertext, bukan teks polos', function () {
        $state = otpState();

        $raw = $state->getAttributes();

        // Yang ada di kolom adalah ciphertext.
        expect($raw['phone'])->not->toBe('628123456789')
            ->and($raw['phone'])->not->toContain('628123456789');

        // ...samba nilainya tidak hilang untuk model.
        expect(SrikandiOtpState::query()->findOrFail($state->id)->phone)
            ->toBe('628123456789');
    });

    it('scopeWherePhone() menemukan record lewat blind index', function () {
        $state = otpState();

        expect(SrikandiOtpState::query()->wherePhone('628123456789')->count())->toBe(1)
            ->and($state->phone_index)->not->toBeNull()
            ->and($state->phone_index)->not->toContain('628123456789');
    });

    it('where("phone", ...) biasa SELALU kosong -- dan itu bukan bug', function () {
        otpState();

        // 🔴 Test ini mengunci KEBALIKAN dari asumsi yang salah.
        //
        // Kalau ada yang menulis `where('phone', ...)` di produksi, hasilnya
        // "tidak ada jendela" -- bukan error. Assertion `not->toBe(0)` akan
        // terlihat seperti perbaikan, padahal justru masalahnya.
        expect(SrikandiOtpState::query()->where('phone', '628123456789')->count())->toBe(0)
            ->and(SrikandiOtpState::query()->wherePhone('628123456789')->count())->toBe(1);
    });

    it('scopeWherePhone() menyamakan berbagai bentuk nomor', function () {
        otpState();

        foreach (['628123456789', '08123456789', '+62 812-3456-789'] as $variant) {
            expect(SrikandiOtpState::query()->wherePhone($variant)->count())
                ->toBe(1, "gagal untuk bentuk: {$variant}");
        }
    });

    it('phone_index ikut diperbarui saat phone diganti', function () {
        $state = otpState();

        $indexAwal = $state->phone_index;

        $state->phone = '628999999999';
        $state->save();

        $indexAkhir = SrikandiOtpState::query()->findOrFail($state->id)->phone_index;

        // Nomor lama harus hilang. Kalau tidak, balasan untuk nomor yang sudah
        // diganti masih akan dianggap milik jendela ini.
        expect($indexAkhir)->not->toBe($indexAwal)
            ->and(SrikandiOtpState::query()->wherePhone('628123456789')->count())->toBe(0)
            ->and(SrikandiOtpState::query()->wherePhone('628999999999')->count())->toBe(1);
    });

    it('`phone` tetap wajib diisi -- tidak ada state tanpa nomor', function () {
        // Berbeda dari `srikandi_clients.phone` yang nullable. State OTP tanpa
        // nomor tidak bisa dicocokkan ke balasan mana pun, jadi membiarkannya
        // nullable hanya menambah record yang tidak berguna dan tidak terlihat.
        expect(fn () => SrikandiOtpState::query()->create([
            'phone' => null,
            'state' => SrikandiOtpState::STATE_PENDING,
            'expires_at' => now()->addMinutes(5),
        ]))->toThrow(QueryException::class);
    });

    it('index memakai kunci sendiri, bukan APP_KEY', function () {
        $index = (new BlindIndex)->make('628123456789');

        $denganKeyLain = new BlindIndex;
        config()->set('srikandi.index_key', 'kunci-index-yang-berbeda');

        expect((new BlindIndex)->make('628123456789'))->not->toBe($index);

        // Dan kembali ke kunci semula, hasilnya sama lagi.
        config()->set('srikandi.index_key', 'kunci-index-test-yang-berbeda-dari-app-key');

        expect((new BlindIndex)->make('628123456789'))->toBe($index);
    });
});

describe('sumber pada srikandi_otp_states', function () {
    it('`sumber` wajib diisi eksplisit -- tidak ada lagi default', function () {
        // 🔴 Kolom `sumber` di `srikandi_otp_states` sekarang NOT NULL tanpa
        // default, sama seperti `srikandi_naskah.sumber`.
        //
        // Default lama (`'default'`) dipakai supaya state OTP yatim tetap bisa
        // dibuat sebelum token terikat ke client. Setelah S3 mewajibkan
        // `sumber` di endpoint, tidak ada caller produksi lagi yang boleh
        // bergantung pada default itu: dari mana pun state dibuat, sumbernya
        // harus diketahui.
        //
        // Kalau test ini dibalik menjadi "boleh kosong", itu berartisomeone
        // mengembalikan default dan membuka kembali jendela OTP tanpa client.
        expect(fn () => SrikandiOtpState::query()->create([
            'phone' => '628123456789',
            'state' => SrikandiOtpState::STATE_PENDING,
            'expires_at' => now()->addMinutes(5),
            'opened_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('sumber bisa diisi eksplisit untuk client tertentu', function () {
        $state = otpState(['sumber' => 'satker-a']);

        expect(SrikandiOtpState::query()->findOrFail($state->id)->sumber)->toBe('satker-a');
    });
});
