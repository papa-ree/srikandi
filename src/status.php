<?php

/*
|--------------------------------------------------------------------------
| Status bale/srikandi
|--------------------------------------------------------------------------
|
| Sumber tunggal untuk halaman status (route `srikandi.status.index`).
|
| Rinciannya ada di `docs/srikandi.md`. File ini hanya ringkasan yang dibaca
| dashboard, dan sengaja ditulis terpisah dari dokumen — lihat catatan di
| `bale/wara/src/status.php` soal kenapa halaman ini tidak mem-parsing Markdown.
|
| Bentuk yang dipakai renderer `x-core::checklist`:
|
|   'sections' => [ ['title' => ..., 'items' => [ ['label','status','note','meta'], ... ] ], ... ]
|
*/

return [

    'title' => 'Status bale/srikandi',

    'subtitle' => 'State OTP dan cache naskah yang hanya bisa diketahui Bale. Scraper adalah transport, '
        .'bukan sumber kebenaran. Seluruh DoD selesai; sisa yang tercatat adalah pekerjaan lanjutan.',

    'updated_at' => '2026-10-01',

    'doc' => [
        'label' => 'Dokumen',
        'href' => 'docs/srikandi.md',
    ],

    'sections' => [

        [
            'title' => 'Fase implementasi',
            'items' => [
                [
                    'label' => 'Kerangka package + 2 migration',
                    'status' => 'done',
                    'meta' => '§2–§3',
                    'note' => 'Tervalidasi di engine sungguhan: `tahun` NOT NULL default 0 dan '
                        .'unique key (account_id, nomor_naskah, tahun).',
                ],
                [
                    'label' => '4 endpoint scraper dengan scope terpisah',
                    'status' => 'done',
                    'meta' => '§5',
                    'note' => 'Memakai token `bale/api` (Bearer) — bukan tabel API key baru.',
                ],
                [
                    'label' => 'State OTP: request idempoten, verify idempoten, batas percobaan',
                    'status' => 'done',
                    'meta' => '§5.1, §5.3',
                    'note' => 'Kode OTP hanya disimpan sebagai hash dan tidak pernah keluar lewat HTTP.',
                ],
                [
                    'label' => 'Kontrak v2: jendela listening + identitas `request_id`',
                    'status' => 'done',
                    'meta' => '§5.0b',
                    'note' => '`otp-request` tanpa `phone` hanya membuka jendela baca — tidak mengarang '
                        .'kode dan tidak mengirim WhatsApp. Nomor tujuan diambil dari device `purpose=otp`, '
                        .'jadi scraper tidak pernah memegang nomor WA.',
                ],
                [
                    'label' => 'Batas bawah jendela `opened_at` di `otp-pending`',
                    'status' => 'done',
                    'note' => 'Membunuh kode basi dari siklus sebelumnya. `opened_at` pakai `>=`, '
                        .'`after` tetap `>` — keduanya sengaja tidak disamakan.',
                ],
                [
                    'label' => 'Listener balasan masuk dari `WaraIncomingMessage`',
                    'status' => 'done',
                    'meta' => '§6',
                    'note' => 'Mencocokkan kode dengan mengabaikan spasi dan tanda hubung. Tidak pernah '
                        .'mengarang record OTP untuk pesan tanpa permintaan yang cocok.',
                ],
                [
                    'label' => 'Ingest naskah dinas dengan inserted / revised / unchanged',
                    'status' => 'done',
                    'meta' => '§5.4',
                    'note' => 'Backend yang memutuskan status lewat perbandingan `row_hash`, bukan scraper.',
                ],
                [
                    'label' => 'Tiga jebakan yang sudah diperbaiki dan diuji',
                    'status' => 'done',
                    'note' => 'Batas percobaan tidak lagi ter-rollback, `revised` benar-benar memicu, '
                        .'dan polling tidak lagi mencari kolom terenkripsi.',
                ],
            ],
        ],

        [
            'title' => 'Pekerjaan lanjutan',
            'items' => [
                [
                    'label' => 'Endpoint `verified` → `consumed`',
                    'status' => 'open',
                    'meta' => 'prioritas tinggi',
                    'note' => 'Nilai `consumed` sudah ada di enum dan kolomnya sudah tersedia, tapi belum '
                        .'ada yang memanggilnya. Tanpa ini scraper memakai ulang `verified` sebagai terminal.',
                ],
                [
                    'label' => 'Scraper membaca kode Srikandi dari jendela',
                    'status' => 'open',
                    'meta' => 'menunggu run live',
                    'note' => 'Kontrak backendnya sudah lengkap dan teruji. Yang belum ada bukti adalah '
                        .'run scraper sungguhan: masih perlu konfirmasi bahwa ia memilih kode Srikandi '
                        .'(bukan kode basi) dari `otp-pending`.',
                ],
                [
                    'label' => 'Halaman daftar state OTP untuk admin',
                    'status' => 'open',
                    'note' => 'Endpoint scraper sudah hidup, tapi belum ada UI. Admin belum bisa melihat '
                        .'kode OTP yang sedang menunggu tanpa membuka database.',
                ],
                [
                    'label' => 'Integrasi scraper `rak-srikandi`',
                    'status' => 'blocked',
                    'meta' => 'luar package ini',
                    'note' => 'Menunggu scraper. Endpoint dan scope sudah siap; yang belum ada adalah sisi '
                        .'scraper yang memanggilnya.',
                ],
            ],
        ],

        [
            'title' => 'Penyimpangan dari spesifikasi (disengaja)',
            'items' => [
                [
                    'label' => 'Autentikasi memakai token `bale/api`, bukan `X-Api-Key`',
                    'status' => 'done',
                    'meta' => '§5.0',
                    'note' => 'Masa berlaku, pencabutan, IP allowlist, throttle, dan UI sudah ada di sana. '
                        .'Membangun tabel kedua berarti membangun ulang semuanya dengan lebih buruk.',
                ],
                [
                    'label' => 'Ditambah kolom `code_hash`',
                    'status' => 'done',
                    'meta' => '§3.1.1',
                    'note' => 'Spesifikasi melarang kode polos tanpa menyatakan di mana bentuk polosnya '
                        .'disimpan. Tanpa kolom hash, listener mustahil mencocokkan kode.',
                ],
                [
                    'label' => 'Status `201` vs `200` pada `otp-request`',
                    'status' => 'done',
                    'meta' => '§5.1',
                    'note' => 'Pembeda "baru dibuat" dari "dipakai ulang", supaya scraper tidak salah '
                        .'mengirim ulang padahal pengiriman kedua memang dilewati.',
                ],
            ],
        ],

    ],

];
