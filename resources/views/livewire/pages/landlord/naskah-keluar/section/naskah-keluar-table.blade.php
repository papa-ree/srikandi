<div>
    {{--
        🔴 Opsi filter BUKAN daftar konstanta.

        `status_tindak_lanjut` berasal dari sistem luar (mis. "KIRIM"/"BELUM") dan
        bisa mendapat nilai baru kapan saja. Kalau daftarnya ditutup di view ini,
        begitu Srikandi menambah status baru baris itu masih tampil tapi tidak
        bisa dicari -- dan tidak ada jejak bahwa filternya sudah usang.

        🔴 `tahun` tetap memuat `0` (tahun tidak diketahui). Nilai itu falsy di
        PHP, jadi filter yang membuang falsy akan membuat baris tanpa tahun
        mustahil dicari.
    --}}
    <livewire:core-shared-components::data-table
        :model="\Bale\Srikandi\Models\SrikandiNaskahKeluar::class"
        :columns="[
            [
                'key' => 'nomor_naskah',
                'label' => __('Nomor Naskah'),
                'sortable' => true,
            ],
            ['key' => 'tujuan_naskah', 'label' => __('Tujuan'), 'sortable' => true, 'hidden' => 'lg'],
            ['key' => 'hal', 'label' => __('Hal'), 'sortable' => false],
            [
                // 🔴 Bukan kolom yang bisa diurutkan: yang diurutkan adalah
                // `tahun`, bukan `nomor_naskah` sendirian -- nomor yang sama bisa
                // muncul di tahun berbeda.
                'key' => 'file_path',
                'label' => __('Berkas'),
                'sortable' => false,
            ],
            ['key' => 'tanggal_naskah', 'label' => __('Tanggal Naskah'), 'sortable' => true, 'hidden' => 'md'],
            ['key' => 'status_tindak_lanjut', 'label' => __('Status Tindak Lanjut'), 'sortable' => false, 'hidden' => 'sm'],
        ]"
        :searchable="['nomor_naskah', 'tujuan_naskah', 'hal']"
        :sort-field="'tanggal_naskah'"
        :sort-direction="'desc'"
        :per-page="25"
        :filter-options="[
            [
                'key' => 'status_tindak_lanjut',
                'label' => __('Status Tindak Lanjut'),
                'options' => $statusFilter,
                'nullLabel' => __('NULL — belum ada status tindak lanjut'),
            ],
            [
                'key' => 'tahun',
                'label' => __('Tahun'),
                'options' => $tahunFilter,
            ],
        ]"
        row-view="srikandi::livewire.pages.landlord.naskah-keluar.section.naskah-keluar-row"
        card-view="srikandi::livewire.pages.landlord.naskah-keluar.section.naskah-keluar-card" />
</div>
