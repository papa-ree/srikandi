<?php

/*
 | ---------------------------------------------------------------------------
 | Tombol "Test kirim" di form client
 | ---------------------------------------------------------------------------
 |
 | Menguji validasi + pemisahan tujuan:
 |   - uji notifikasi OTP  -> Nomor Akun (`phone`), BUKAN penerima naskah
 |   - uji notifikasi naskah -> SEMUA penerima aktif, BUKAN Nomor Akun
 |
 | Pengiriman memakai `NotificationTester` yang memanggil `WaraManager`
 | langsung (sinkron), jadi keberhasilan bisa dijamin lewat request HTTP ke
 | gateway palsu — bukan lewat `Notification::fake()`.
 |
 */

use App\Models\User;
use Bale\Srikandi\Commands\InstallCommand;
use Bale\Srikandi\Livewire\Pages\Landlord\Client\Form as ClientForm;
use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiNotifyRecipient;
use Bale\Srikandi\Support\OtpPhone;
use Bale\Wara\Models\WaraClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

require_once __DIR__.'/../helpers.php';

beforeEach(function () {
    srikandiSetup()->call($this);

    Permission::firstOrCreate(['name' => 'srikandi.client.update', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('srikandi.client.update');

    Livewire::actingAs($this->user);
});

/**
 * Client default yang sudah punya device wara untuk purpose `otp` dan
 * `notify`, plus nomor akun tersimpan. Device disemai lewat helper `helpers.php`
 * (route wara + session siap kirim).
 */
function kirimTestClient(array $overrides = []): SrikandiClient
{
    seedSendableDevice('dev-otp', 'otp', '628000000001@s.whatsapp.net');
    seedSendableDevice('dev-notify', 'notify', '628000000002@s.whatsapp.net');

    $waraClient = WaraClient::query()
        ->where('name', InstallCommand::WARA_CLIENT_NAME)
        ->firstOrFail();

    $client = SrikandiClient::query()->where('slug', 'default')->firstOrFail();
    $client->forceFill(array_merge([
        'wara_client_id' => $waraClient->getKey(),
        'otp_purpose' => 'otp',
        'notify_purpose' => 'notify',
        'phone' => '628111111111',
    ], $overrides))->save();

    return $client->refresh();
}

/**
 * Penerima notifikasi langsung di database (melewati form).
 */
function kirimTestRecipient(
    SrikandiClient $client,
    string $phone,
    bool $active = true,
): SrikandiNotifyRecipient {
    $recipient = new SrikandiNotifyRecipient;
    $recipient->forceFill(['client_id' => $client->getKey()]);
    $recipient->fill([
        'type' => SrikandiNotifyRecipient::TYPE_PHONE,
        'is_active' => $active,
    ]);
    $recipient->setValue(app(OtpPhone::class)->normalize($phone));
    $recipient->save();

    return $recipient;
}

function assertKirimKe(array $phones): void
{
    Http::assertSent(fn () => true);

    $actual = [];

    foreach (Http::recorded() as [$request, $response]) {
        $actual[] = $request['phone'];
    }

    expect($actual)->toBe($phones);
}

describe('tombol test kirim notifikasi OTP', function () {
    it('mengirim ke Nomor Akun, bukan ke penerima naskah', function () {
        $client = kirimTestClient();
        kirimTestRecipient($client, '628999999999');

        Livewire::test(ClientForm::class, ['client' => $client])
            ->call('testOtpNotification')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        // Hanya SATU request, dan tujuannya nomor akun — penerima naskah tidak
        // boleh ikut tersentuh.
        assertKirimKe(['628111111111@s.whatsapp.net']);
    });

    it('memakai nomor akun yang baru diketik sebelum disimpan', function () {
        $client = kirimTestClient();

        Livewire::test(ClientForm::class, ['client' => $client])
            ->set('secretActions.phone.replace', true)
            ->set('phone', '081222223333')
            ->call('testOtpNotification')
            ->assertHasNoErrors();

        assertKirimKe(['6281222223333@s.whatsapp.net']);
    });

    it('menolak uji kalau Nomor Akun belum ada', function () {
        $client = kirimTestClient(['phone' => null]);

        Livewire::test(ClientForm::class, ['client' => $client])
            ->call('testOtpNotification')
            ->assertHasErrors('phone');

        Http::assertNothingSent();
    });
});

describe('tombol test kirim notifikasi naskah', function () {
    it('mengirim ke SEMUA penerima aktif dan melewati yang nonaktif', function () {
        $client = kirimTestClient();
        kirimTestRecipient($client, '628123450001');
        kirimTestRecipient($client, '628123450002', active: false);
        kirimTestRecipient($client, '628123450003');

        Livewire::test(ClientForm::class, ['client' => $client])
            ->call('testNaskahNotification')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        // Tujuan hanya penerima naskah — Nomor Akun (628111111111) tidak boleh
        // menerima notifikasi naskah.
        assertKirimKe([
            '628123450001@s.whatsapp.net',
            '628123450003@s.whatsapp.net',
        ]);
    });

    it('mengirim ke penerima baru yang belum disimpan', function () {
        $client = kirimTestClient();

        Livewire::test(ClientForm::class, ['client' => $client])
            ->set('newRecipientPhone', '081234500009')
            ->call('addRecipient')
            ->assertHasNoErrors()
            ->call('testNaskahNotification')
            ->assertHasNoErrors();

        assertKirimKe(['6281234500009@s.whatsapp.net']);
    });

    it('menolak uji kalau tidak ada penerima aktif', function () {
        $client = kirimTestClient();
        kirimTestRecipient($client, '628123450001', active: false);

        Livewire::test(ClientForm::class, ['client' => $client])
            ->call('testNaskahNotification')
            ->assertHasErrors('newRecipientPhone');

        Http::assertNothingSent();
    });

    it('TIDAK jatuh ke Nomor Akun sebagai pengganti penerima', function () {
        $client = kirimTestClient();
        kirimTestRecipient($client, '628123450001', active: false);

        Livewire::test(ClientForm::class, ['client' => $client])
            ->call('testNaskahNotification')
            ->assertHasErrors('newRecipientPhone');

        Http::assertNothingSent();
    });
});

it('menolak kedua uji sebelum client disimpan', function () {
    Livewire::test(ClientForm::class)
        ->set('nama', 'Client Baru')
        ->set('slug', 'client-baru')
        ->call('testOtpNotification')
        ->assertDispatched('toast')
        ->call('testNaskahNotification')
        ->assertDispatched('toast');

    Http::assertNothingSent();
});
