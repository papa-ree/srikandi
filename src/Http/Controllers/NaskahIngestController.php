<?php

namespace Bale\Srikandi\Http\Controllers;

use Bale\Srikandi\Exceptions\SrikandiException;
use Bale\Srikandi\Services\NaskahIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
         * Paket belum production, jadi `sumber` wajib dari awal -- bukan
         * opsional dengan fallback `default`. Default itu berarti naskah yang
         * gagal menentukan pemiliknya tersimpan seolah-olah milik client
         * `default`, dan kesalahan itu tidak akan muncul di mana pun karena
         * yang tersimpan terlihat valid.
         *
         * 🔴 `sumber` HARUS SLUG YANG ADA, dan ini baru bisa ditutup sekarang.
         *
         * Awalnya `sumber` hanya dicek bentuknya, dengan alasan scraper belum
         * punya alur credentials. Alasan itu sudah kedaluwarsa: S3.1 sudah
         * memberi scraper cara membaca daftar client dan mengambil kredensial,
         * jadi scraper sekarang bisa -- dan harus -- mengirim slug yang memang
         * milik client sungguhan.
         *
         * Yang dicek di sini hanya KEBERADAAN slug, bukan apakah client-nya
         * aktif atau punya kredensial. Alasannya: `srikandi_clients` sengaja
         * TIDAK punya foreign key dari `srikandi_naskah.sumber`, supaya
         * dokumen yang sudah tersimpan tidak hilang hanya karena operator
         * mengganti nama atau menonaktifkan client. Jadi integritas referensial
         * dicek di pintu masuk, dan tidak ditegakkan retroactive.
         *
         * 🔴 Kenapa ini harus 422 dan bukan diterima diam-diam.
         *
         * Gejalanya kalau slug salah terus diterima: naskah tetap masuk dengan
         * pemilik yang salah. Karena unique key-nya `(sumber, nomor_naskah,
         * tahun)`, naskah milik akun A yang terkirim dengan slug salah akan
         * tersimpan sebagai dokumen terpisah -- bukan menimpa, jadi tidak ada
         * yang hilang, tapi operator melihat riwayat naskah yang tidak pernah
         * ada. Dan karena slug salah masih "berbentuk valid", tidak ada filter
         * yang membuatnya mencolok.
         */
        $validated = $request->validate([
            'sumber' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9-]+$/',
                Rule::exists('srikandi_clients', 'slug'),
            ],
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
