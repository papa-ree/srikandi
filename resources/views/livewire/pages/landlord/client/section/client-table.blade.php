<div class="space-y-4">

    {{--
        🔴 KOTAK PENCARIAN NOMOR TERPISAH dari kotak pencarian bawaan tabel.

        Kotak bawaan menambah `like %query%` ke kolom yang dikasih lewat
        `searchable`. `phone` terenkripsi, jadi `like` di sana tidak akan pernah
        cocok — dan tidak ada error, hanya daftar kosong yang selalu muncul.
        Yang bisa dicari adalah `phone_index` (HMAC dari nomor ternormalisasi),
        dan itu exact match, bukan substring.

        Input ini terikat ke state `ClientTable`, bukan ke state tabel, jadi
        perubahan di sini memutar ulang tabel lewat `:key` di bawah.
    --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

        <div class="w-full sm:max-w-xs">
            <label for="srikandi-client-phone-search" class="sr-only">
                {{ __('Cari berdasarkan nomor tujuan') }}
            </label>

            <div class="relative">
                <input id="srikandi-client-phone-search"
                    type="search"
                    autocomplete="off"
                    inputmode="tel"
                    placeholder="{{ __('Cari nomor tujuan…') }}"
                    wire:model.live.debounce.400ms="phoneSearch"
                    class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900
                           text-sm text-gray-900 dark:text-gray-100 shadow-sm
                           focus:border-indigo-500 focus:ring-indigo-500" />

                @if ($phoneSearch !== '')
                    <button type="button"
                        wire:click="$set('phoneSearch', '')"
                        class="absolute inset-y-0 end-2 flex items-center text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <span class="sr-only">{{ __('Bersihkan pencarian nomor') }}</span>
                        <span aria-hidden="true">&times;</span>
                    </button>
                @endif
            </div>

            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Cocok persis dengan nomor tujuan, boleh juga ditulis lokal (0812…) atau internasional (62812…).') }}
            </p>
        </div>

        @if ($phoneSearch !== '')
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Penyaring nomor aktif. Hapus kolom di atas untuk melihat semua client.') }}
            </span>
        @endif
    </div>

    <livewire:core-shared-components::data-table
        :key="$this->tableKey()"
        :model="\Bale\Srikandi\Models\SrikandiClient::class"
        :with="['waraClient']"
        :constraints="$this->constraints()"
        :columns="[
            ['key' => 'nama', 'label' => __('Client'), 'sortable' => true],
            ['key' => 'slug', 'label' => __('Sumber'), 'sortable' => true, 'hidden' => 'lg'],
            [
                // Bukan kolom database — `wara_clients` ada di tabel lain, dan
                // nama device tidak boleh bisa diurutkan dari sini. Sorting
                // dimatikan karena `orderBy('waraClient')` akan jadi error SQL
                // yang jauh lebih sulit dibaca daripada relasi yang gagal dimuat.
                'key' => 'device',
                'label' => __('Device Wara'),
                'sortable' => false,
            ],
            [
                // `isReadyForScraper()` adalah method, bukan kolom. Sama
                // seperti `device`, sorting dinonaktifkan.
                'key' => 'readiness',
                'label' => __('Kesiapan'),
                'hidden' => 'lg',
                'sortable' => false,
            ],
            ['key' => 'is_active', 'label' => __('Status'), 'sortable' => true, 'hidden' => 'sm'],
            ['key' => 'created_at', 'label' => __('Dibuat'), 'sortable' => true, 'hidden' => 'xl'],
        ]"
        :filter-options="[
            [
                'key' => 'is_active',
                'label' => __('Status'),
                'options' => [
                    '1' => __('Aktif'),
                    '0' => __('Nonaktif'),
                ],
            ],
        ]"
        :searchable="['nama', 'slug']"
        :sort-field="'created_at'"
        :sort-direction="'desc'"
        :per-page="15"
        row-view="srikandi::livewire.pages.landlord.client.section.client-row"
        card-view="srikandi::livewire.pages.landlord.client.section.client-card" />
</div>