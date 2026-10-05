<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Services\NaskahIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/srikandi/naskah-dinas (spec §5.4).
 *
 * Endpoint ini yang menyimpan hasil pembacaan list Srikandi, dan endpoint ini
 * yang memutuskan `inserted` / `revised` / `unchanged` lewat perbandingan
 * `row_hash` — scraper tidak pernah mengirim status "baru".
 *
 * 🔴 FIELD `bale_id` (UUID `bale_lists.id`) MEWAKILI DUA HAL SEKALI: filter
 * unik `(bale_id, nomor_naskah, tahun)` DAN pernyataan "naskah ini milik
 * organisasi ini". Menaruh UUID yang salah di sini tidak akan gagal diam-diam -
 * naskah deterioro dokumen resmi akan tersimpan di bawah organisasi yang salah
 * dan unique key ikut berubah, sehingga naskah yang sama bisa masuk dua kali
 * dengan UUID berbeda. Karena itu payload WAJIB cocok dengan `bale_id` token.
 */
class NaskahIngestController extends SrikandiController
{
    public function __construct(protected NaskahIngestService $naskah) {}

    public function __invoke(Request $request): JsonResponse
    {
        // 🔴 TIDAK ADA `bale_id` di payload, dan itu disengaja.
        //
        // `srikandi_naskah` adalah cache dari SATU mailbox SRIKANDI. Tidak ada
        // hubungannya dengan `bale_lists` (katalog tenant database), jadi
        // meminta UUID organisasi di sini hanya memaksa scraper mengirim data
        // yang tidak berarti - dan kalau salah, dokumen resmi tersimpan di
        // bawah tenant yang salah tanpa error yang terlihat.
        //
        // Identitas pemanggil tetap dijaga, tapi lewat api token yang sudah
        // ada (`srikandi.naskah.write`), bukan lewat kolom di body.
        /*
         * 🔴 `sumber` WAJIB, dan itu perubahan breaking.
         *
         * Sebelumnya payload `{ items: [...] }` tidak membawa identitas asal,
         * karena `srikandi_naskah` dulu dipandang sebagai cache dari SATU
         * mailbox. Sekarang satu instalasi bisa punya beberapa akun Srikandi
         * yang semuanya menulis ke tabel yang sama, jadi tanpa `sumber` dua
         * akun yang punya nomor naskah sama akan saling menimpa.
         *
         * Gejalanya bukan error: yang kedua dilaporkan `unchanged`, jadi satu
         * akun diam-diam kehilangan riwayat naskahnya.
         *
         * `sumber` TIDAK diverifikasi terhadap `srikandi_clients` di sini --
         * hanya dicek bentuknya. Validasi keberadaan client (dan penolakan
         * kalau client-nya tidak aktif) adalah urusan S3, bersama token binding
         * per client. Yang sengaja tidak ditambahkan sekarang: endpoint ini
         * dipanggil scraper yang belum punya alur credentials (S3), jadi
         * mewajibkan client yang sudah ada akan memutus semua ingest sebelum
         * S3 selesai.
         *
         * Paket belum production, jadi `sumber` wajib dari awal -- bukan
         * opsional dengan fallback `default`. Default itu berarti naskah yang
         * gagal menentukan pemiliknya tersimpan seolah-olah milik client
         * `default`, dan kesalahan itu tidak akan muncul di mana pun karena
         * yang tersimpan terlihat valid.
         */
        $validated = $request->validate([
            'sumber' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'items' => ['present', 'array', 'max:500'],
            'items.*' => ['array'],
        ]);

        try {
            $counts = $this->naskah->ingest($validated['items'], $validated['sumber']);
        } catch (SrikandiException $e) {
            return $this->failure($e);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }

        return $this->ok([
            'inserted' => $counts['inserted'],
            'revised' => $counts['revised'],
            'unchanged' => $counts['unchanged'],
            'skipped' => $counts['skipped'],
        ]);
    }
}
