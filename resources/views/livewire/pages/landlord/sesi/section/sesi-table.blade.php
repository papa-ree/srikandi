<div>
    {{--
        🔴 Opsi filter `sumber` dibangun dari data (`distinct`), BUKAN daftar
        konstanta. Client bisa ditambah kapan saja; daftar yang ditutup di sini
        akan membuat sesi client baru mustahil difilter.

        🔴 Opsi filter `status` justru daftar TETAP (`success`/`failed`). Nilai
        itu satu-satunya yang diterima endpoint snapshot, dan daftar tetap
        membuat "yang gagal" bisa disaring bahkan sebelum ada sesi gagal.
    --}}
    <livewire:core-shared-components::data-table
        :model="\Bale\Srikandi\Models\SrikandiSessionSnapshot::class"
        :columns="[
            [
                'key' => 'started_at',
                'label' => __('Mulai'),
                'sortable' => true,
            ],
            ['key' => 'status', 'label' => __('Status'), 'sortable' => false, 'hidden' => 'sm'],
            ['key' => 'finished_at', 'label' => __('Selesai'), 'sortable' => true, 'hidden' => 'md'],
            ['key' => 'error_code', 'label' => __('Error'), 'sortable' => false, 'hidden' => 'lg'],
        ]"
        :searchable="['sumber', 'error_code']"
        :sort-field="'started_at'"
        :sort-direction="'desc'"
        :per-page="25"
        :filter-options="[
            [
                'key' => 'sumber',
                'label' => __('Sumber'),
                'options' => $sumberFilter,
            ],
            [
                'key' => 'status',
                'label' => __('Status'),
                'options' => [
                    'success' => __('Sukses'),
                    'failed' => __('Gagal'),
                ],
            ],
        ]"
        row-view="srikandi::livewire.pages.landlord.sesi.section.sesi-row"
        card-view="srikandi::livewire.pages.landlord.sesi.section.sesi-card" />
</div>
