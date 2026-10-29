<?php

namespace Bale\Srikandi\Livewire\Pages\Landlord\Overview;

use Bale\Srikandi\Livewire\Pages\Landlord\Overview\Concerns\ResolvesBulan;
use Bale\Srikandi\Support\OverviewPeriod;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Overview Srikandi (landlord).
 *
 * 🔴 Komponen ini TIPIS: seluruh query ada di anak `Section/*`, bukan di sini.
 *
 * Yang dipegang halaman ini hanya SATU hal: periode yang sedang dilihat, entah
 * bulan (`?bulan=Y-m`) atau rentang (`?dari=&sampai=`). Itu sengaja karena
 * nilainya harus hidup di URL supaya tautan laporan bisa dikirim ke orang lain,
 * dan URL hanya bisa di-ikat ke satu komponen. Semua turunannya menerima
 * periode yang sama sebagai prop, dan tombol export membawa query yang sama.
 *
 * 🔴 `#[Url]` adalah input pengguna, jadi `mount()` menormalkannya dulu.
 * `?bulan=abc` tidak boleh sampai ke `Carbon::createFromFormat()` di anak --
 * hasilnya exception, bukan halaman kosong. Rentang juga tidak pernah ditolak
 * dengan error: `OverviewPeriod` membalik, memotong ke 90 hari, atau kembali ke
 * bulan berjalan, dan setiap koreksi meninggalkan pesan di `$periodError`.
 */
#[Layout('core::layouts.app')]
#[Title('Overview Srikandi')]
class Index extends Component
{
    use AuthorizesRequests;
    use ResolvesBulan;

    /**
     * Periode bulan, format `Y-m`. Tetap ada walau mode rentang aktif supaya
     * tautan lama dan pilihan "Bulanan" tidak kehilangan bulan terakhir.
     */
    #[Url(as: 'bulan', history: true)]
    public string $bulan = '';

    #[Url(as: 'dari', history: true)]
    public string $dari = '';

    #[Url(as: 'sampai', history: true)]
    public string $sampai = '';

    /**
     * `bulan` atau `rentang`. Tidak disimpan di URL: mode diturunkan dari ada
     * tidaknya `dari`/`sampai`, sehingga tautan yang hanya berisi `?bulan=`
     * tetap dibuka dalam mode bulanan.
     */
    public string $mode = 'bulan';

    public ?string $periodError = null;

    public function mount(): void
    {
        // 🔴 Permission yang dipakai `srikandi.client.read`, bukan
        // `srikandi.naskah.read`.
        //
        // Overview adalah pintu masuk grup Srikandi: ia menampilkan kesehatan
        // client LEBIH DULU sebelum statistik naskah. Kalau dijaga dengan
        // `naskah.read`, operator yang hanya berhak mengelola akun (tanpa akses
        // arsip naskah) tidak punya halaman muka sama sekali.
        $this->authorize('srikandi.client.read');

        $this->bulan = $this->normalizeBulan($this->bulan);
        $this->mode = ($this->dari !== '' || $this->sampai !== '') ? 'rentang' : 'bulan';

        if ($this->mode === 'rentang') {
            $this->normalizeRange();
        }
    }

    /**
     * Pindah mode tanpa memuat ulang halaman.
     *
     * Saat masuk ke Rentang, batas diisi dari bulan yang sedang aktif supaya
     * operator melihat data yang sama sebelum mempersempitnya. Saat kembali ke
     * Bulanan, `dari`/`sampai` dikosongkan agar URL tidak lagi menyatakan
     * rentang.
     */
    public function setMode(string $mode): void
    {
        if ($mode === 'rentang') {
            $period = OverviewPeriod::fromQuery($this->bulan, null, null);
            $this->dari = $period->start->toDateString();
            $this->sampai = $period->end->toDateString();
            $this->mode = 'rentang';
            $this->periodError = null;

            return;
        }

        $this->mode = 'bulan';
        $this->dari = '';
        $this->sampai = '';
        $this->periodError = null;
    }

    public function updatedDari(): void
    {
        $this->normalizeRange();
    }

    public function updatedSampai(): void
    {
        $this->normalizeRange();
    }

    /**
     * Filter bulanan dipilih dari dua kontrol: bulan (1-12) dan tahun.
     *
     * 🔴 `$bulan` tetap satu-satunya sumber kebenaran (`Y-m`, juga yang ada di
     * URL). Dua properti terpisah untuk bulan dan tahun akan menjadi salinan
     * kedua yang bisa menyimpang saat tautan dibuka atau `bulan` diubah.
     */
    public function setBulanBulan(string $bulan): void
    {
        $this->bulan = $this->normalizeBulan(
            substr($this->normalizeBulan($this->bulan), 0, 4).'-'.str_pad($bulan, 2, '0', STR_PAD_LEFT),
        );
    }

    public function setBulanTahun(string $tahun): void
    {
        $this->bulan = $this->normalizeBulan($tahun.'-'.substr($this->normalizeBulan($this->bulan), 5, 2));
    }

    public function render()
    {
        $period = OverviewPeriod::fromQuery($this->bulan, $this->dari, $this->sampai);
        $monthOptions = $this->bulanOptions();

        return view('srikandi::livewire.pages.landlord.overview.index', [
            'monthOptions' => $monthOptions,
            // Tahun ikut daftar bulan yang ada datanya, terbaru dulu. Tahun dari
            // `?bulan=` selalu ikut supaya select tidak menampilkan tahun lain
            // saat tautan menunjuk tahun tanpa naskah.
            'tahunOptions' => $this->tahunOptions($monthOptions),
            'namaBulan' => array_map(
                fn (int $month): string => Carbon::create(null, $month, 1)->locale('id')->translatedFormat('F'),
                range(1, 12),
            ),
            'periodeLabel' => $period->label(),
            'periodKey' => $period->key(),
            'exportQuery' => $period->query(),
            // Batas kalender "sampai": 90 hari inklusif dari tanggal awal.
            // Server tetap memotong; ini mencegah pilihan yang pasti ditolak.
            'sampaiMaxDate' => $period->isRange
                ? $period->start->copy()->addDays(OverviewPeriod::MAX_DAYS - 1)->toDateString()
                : null,
        ]);
    }

    /**
     * @param  array<string, string>  $monthOptions
     * @return list<string>
     */
    protected function tahunOptions(array $monthOptions): array
    {
        $years = array_map(
            fn (string $key): string => substr($key, 0, 4),
            array_keys($monthOptions),
        );

        $years[] = substr($this->normalizeBulan($this->bulan), 0, 4);
        $years = array_values(array_unique($years));
        rsort($years);

        return $years;
    }

    /**
     * Tulis kembali rentang yang BENAR-BENAR dipakai ke input.
     *
     * Tanpa ini, operator yang memilih 6 bulan melihat tanggal yang dipilih di
     * input sementara dokumen dan halaman memakai 90 hari. Input yang berbeda
     * dari data adalah kebohongan yang tidak terlihat di test angka.
     */
    protected function normalizeRange(): void
    {
        if ($this->dari === '' && $this->sampai === '') {
            $this->periodError = null;

            return;
        }

        if ($this->dari === '') {
            $this->dari = $this->sampai;
        }

        if ($this->sampai === '') {
            $this->sampai = $this->dari;
        }

        $period = OverviewPeriod::fromQuery(null, $this->dari, $this->sampai);
        $this->dari = $period->start->toDateString();
        $this->sampai = $period->end->toDateString();
        $this->periodError = $period->notice;
    }
}
