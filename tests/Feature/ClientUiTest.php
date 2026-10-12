<?php

/*
 | ---------------------------------------------------------------------------
 | Pengelolaan penerima notifikasi di form client (S5.2)
 | ---------------------------------------------------------------------------
 |
 | Semua test di sini menguji hal yang tidak kelihatan dari model: daftar
 | penerima hidup di state Livewire, dan satu-satunya jalan menyentuh database
 | adalah `save()` -> `syncRecipients()`. Kalau sinkronisasinya salah, gejala
 | yang paling mahal adalah "nomor lama tidak pernah terhapus" atau "nomor baru
 | tersimpan dua kali" - dan keduanya tidak melempar error sama sekali.
 |
 | 🔴 Nomor tersimpan TIDAK PERNAH kembali ke browser. `loadRecipients()` hanya
 | mengisi `value_masked`; `value` dikosongkan. Test kebocoran di bawah menjaga
 | janji itu, karena satu `value` yang terisi otomatis membuat seluruh daftar
 | nomor tergantung di snapshot Livewire yang bisa dibaca dari DOM.
 |
 */

use App\Models\User;
use Bale\Srikandi\Livewire\Pages\Landlord\Client\Form as ClientForm;
use Bale\Srikandi\Models\SrikandiClient;
use Bale\Srikandi\Models\SrikandiNotifyRecipient;
use Bale\Srikandi\Support\OtpPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

require_once __DIR__.'/../helpers.php';

beforeEach(function () {
    // `srikandiSetup()` mengembalikan Closure yang memakai `$this`, jadi harus
    // dipanggil di dalam konteks test (lihat catatan di SrikandiClientTest).
    srikandiSetup()->call($this);

    Permission::firstOrCreate(['name' => 'srikandi.client.update', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('srikandi.client.update');

    Livewire::actingAs($this->user);
});

/**
 * Client `default` yang dibuat `srikandiSetup()`, siap diedit lewat form.
 */
function srikandiUiClient(array $overrides = []): SrikandiClient
{
    $client = SrikandiClient::query()->where('slug', 'default')->firstOrFail();

    if ($overrides !== []) {
        $client->forceFill($overrides)->save();
    }

    return $client->refresh();
}

/**
 * Penerima tersimpan langsung di database (melewati form).
 */
function srikandiUiRecipient(
    SrikandiClient $client,
    string $phone,
    bool $active = true,
    ?string $label = null,
): SrikandiNotifyRecipient {
    $recipient = new SrikandiNotifyRecipient;
    $recipient->forceFill(['client_id' => $client->getKey()]);
    $recipient->fill([
        'type' => SrikandiNotifyRecipient::TYPE_PHONE,
        'label' => $label,
        'is_active' => $active,
    ]);
    $recipient->setValue(app(OtpPhone::class)->normalize($phone));
    $recipient->save();

    return $recipient;
}

describe('form client: pengelolaan penerima notifikasi', function () {
    it('menambah penerima baru lalu menyimpannya dalam bentuk ternormalisasi', function () {
        $client = srikandiUiClient();

        Livewire::test(ClientForm::class, ['client' => $client])
            ->set('newRecipientPhone', '081234567890')
            ->set('newRecipientLabel', 'Admin')
            ->call('addRecipient')
            ->assertHasNoErrors()
            ->assertSet('newRecipientPhone', '')
            ->assertSet('newRecipientLabel', '')
            ->call('save')
            ->assertHasNoErrors();

        $recipient = $client->refresh()->notifyRecipients()->sole();

        expect($recipient->type)->toBe(SrikandiNotifyRecipient::TYPE_PHONE)
            ->and($recipient->value)->toBe('6281234567890')
            ->and($recipient->label)->toBe('Admin')
            ->and($recipient->is_active)->toBeTrue()
            ->and($recipient->value_index)->not->toBeEmpty();
    });

    it('menolak nomor penerima yang sama dua kali', function () {
        $client = srikandiUiClient();

        Livewire::test(ClientForm::class, ['client' => $client])
            ->set('newRecipientPhone', '081234567890')
            ->call('addRecipient')
            ->assertHasNoErrors()
            // Bentuk berbeda, nomor sama setelah normalisasi.
            ->set('newRecipientPhone', '+6281234567890')
            ->call('addRecipient')
            ->assertHasErrors('newRecipientPhone');
    });

    it('menolak nomor penerima yang tidak valid', function () {
        $client = srikandiUiClient();

        Livewire::test(ClientForm::class, ['client' => $client])
            ->set('newRecipientPhone', 'abc')
            ->call('addRecipient')
            ->assertHasErrors('newRecipientPhone');
    });

    it('TIDAK membocorkan nomor penerima tersimpan ke HTML', function () {
        $client = srikandiUiClient();
        srikandiUiRecipient($client, '628123456789', label: 'Admin');

        $component = Livewire::test(ClientForm::class, ['client' => $client]);

        // `value` sengaja kosong; yang boleh tampil hanya masker 4 digit akhir.
        $component
            ->assertSet('recipients.0.value', '')
            ->assertSet('recipients.0.label', 'Admin')
            ->assertSee('6789');

        // Diperiksa dari HTML, bukan dari property: kalau `value` terisi,
        // nomornya muncul di DOM/snapshot dan assertion property di atas tidak
        // akan menangkapnya.
        expect($component->html())->not->toContain('628123456789');
    });

    it('memuat penerima tersimpan ke dalam daftar form', function () {
        $client = srikandiUiClient();
        srikandiUiRecipient($client, '628111111111', active: false, label: 'Nonaktif');

        Livewire::test(ClientForm::class, ['client' => $client])
            ->assertSet('recipients.0.label', 'Nonaktif')
            ->assertSet('recipients.0.is_active', false)
            ->assertSet('recipients.0.value', '');
    });

    it('menyimpan perubahan label dan status aktif penerima tersimpan', function () {
        $client = srikandiUiClient();
        $recipient = srikandiUiRecipient($client, '628123456789', label: 'Lama');

        Livewire::test(ClientForm::class, ['client' => $client])
            ->set('recipients.0.label', 'Baru')
            ->set('recipients.0.is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $recipient->refresh();

        expect($recipient->label)->toBe('Baru')
            ->and($recipient->is_active)->toBeFalse()
            // Nomor tidak pernah ikut berubah - ia tidak pernah dikirim ke browser.
            ->and($recipient->value)->toBe('628123456789');
    });

    it('menghapus penerima tersimpan dari database saat save', function () {
        $client = srikandiUiClient();
        srikandiUiRecipient($client, '628123456789');

        Livewire::test(ClientForm::class, ['client' => $client])
            ->call('removeRecipient', 0)
            ->call('save')
            ->assertHasNoErrors();

        expect($client->refresh()->notifyRecipients()->count())->toBe(0);
    });

    it('membuat client baru beserta penerimanya dalam satu kali simpan', function () {
        Livewire::test(ClientForm::class)
            ->set('nama', 'Client Baru')
            ->set('slug', 'client-baru')
            ->set('newRecipientPhone', '081200000002')
            ->set('newRecipientLabel', 'Notif')
            ->call('addRecipient')
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasNoErrors();

        $client = SrikandiClient::query()->where('slug', 'client-baru')->firstOrFail();

        expect($client->nama)->toBe('Client Baru')
            ->and($client->notifyRecipients()->count())->toBe(1)
            ->and($client->notifyRecipients()->sole()->value)->toBe('6281200000002');
    });
});
