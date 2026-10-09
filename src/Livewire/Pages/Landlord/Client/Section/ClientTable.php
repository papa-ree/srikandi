<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Client\Section;

use Bale\Srikandi\Support\BlindIndex;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tabel client Srikandi.
 *
 * 🔴 TIDAK memakai `HasDeleteOption`, dan itu bukan kelalaian.
 *
 * Client adalah sumber kredensial yang sudah dipakai scraper dan naskah yang
 * sudah tercatat. Menghapus client akan meninggalkan `srikandi_naskah` yang
 * menunjuk `slug` yang sudah tidak ada, dan riwayat login yang tidak bisa
 * ditelusuri. Menonaktifkan (`is_active = false`) menghentikan scraper tanpa
 * merusak referensinya, jadi itulah satu-satunya cara mencabut akses.
 */
class ClientTable extends Component
{
    use AuthorizesRequests;

    /**
     * 🔴 Pencarian nomor TIDAK lewat `searchable` milik data-table.
     *
     * `searchable` menambah `like %query%` ke kolom yang diberi. `phone` dan
     * `notify_phone` terenkripsi, jadi `like` di sana tidak akan pernah cocok —
     * bukan error, hanya selalu kosong, dan itu penyebab paling umum "kenapa cari
     * by-nomor tidak ketemu padahal nomornya benar".
     *
     * Yang bisa dicari adalah `phone_index`: HMAC dari nomor ternormalisasi.
     * Pencariannya exact (`=`) lewat `constraints`, bukan `like`, karena hash
     * tidak punya awalan yang bisa dicari sebagian.
     */
    #[Url(as: 'phone', history: true)]
    public string $phoneSearch = '';

    public function mount(): void
    {
        $this->authorize('srikandi.client.read');
    }

    /**
     * 🔴 Remount data-table setiap pencarian berubah, karena `constraints` hanya
     * dibaca saat mount.
     *
     * Mengubah `constraints` dari komponen induk setelah mount tidak akan
     * membuat tabel ikut berubah — anak tidak dirender ulang saat induknya
     * berubah. Mengganti `:key` memaksa tabel dibangun ulang dengan constraint
     * baru, dan sekaligus mengembalikan paginasi ke halaman 1. Tanpa ini, admin
     * yang berada di halaman 5 lalu dicari nomornya akan melihat "tidak ada
     * hasil" padahal hasilnya ada di halaman 1.
     */
    public function tableKey(): string
    {
        return 'srikandi-client-table-'.md5($this->phoneSearch);
    }

    /**
     * Constraint `phone_index` dari pencarian nomor.
     *
     * `BlindIndex::make()` menormalisasi inputnya sendiri dengan aturan yang
     * sama persis dengan `PhoneNumber::toInternational()`, jadi tidak ada
     * normalisasi kedua di sini — dua aturan berbeda akan membuat satu nomor
     * cocok di form tapi tidak cocok di pencarian.
     *
     * @return array<string, string>
     */
    public function constraints(): array
    {
        $index = (new BlindIndex)->make($this->phoneSearch);

        // Nomor yang tidak menghasilkan digit tidak menyaring apa pun.
        // `phone_index` yang kosong tidak pernah disimpan, jadi memfilternya
        // dengan `''` akan mengembalikan daftar kosong yang menyesatkan.
        if ($index === '') {
            return [];
        }

        return ['phone_index' => $index];
    }

    public function render()
    {
        return view('srikandi::livewire.pages.landlord.client.section.client-table');
    }
}
