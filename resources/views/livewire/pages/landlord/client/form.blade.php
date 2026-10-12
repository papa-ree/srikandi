<div class="space-y-6">
    <x-core::breadcrumb
        :items="[['label' => __('Client Srikandi'), 'route' => 'srikandi.client.index']]"
        :active="$client?->exists ? __('Edit Client') : __('Client Baru')" />

    <x-core::page-header
        :title="$client?->exists ? __('Edit Client Srikandi') : __('Client Srikandi Baru')"
        :subtitle="__('Akun yang dipakai scraper untuk masuk ke Srikandi dan mengambil OTP.')">
        <x-slot name="action">
            <x-core::secondary-button
                link
                href="{{ route('srikandi.client.index') }}"
                label="{{ __('Kembali ke daftar') }}" />
        </x-slot>
    </x-core::page-header>

    {{-- Kredensial tidak pernah kembali ke browser. --}}
    <x-core::alert variant="warning" :title="__('Kredensial tidak pernah ditampilkan lagi')">
        {{ __('Isi kolom hanya menampilkan "terisi" atau "belum diisi". Nilai yang sudah tersimpan tidak pernah dikirim ke halaman ini. Untuk mengganti atau menghapus, centang aksi yang sesuai lalu isi kolomnya.') }}
    </x-core::alert>

    @if ($client?->exists && $client->credentials_rotated_at !== null)
        <x-core::alert variant="info" :title="__('Kredensial terakhir diganti')">
            {{ __('Rotasi terakhir: :time. Setelah rotasi, scraper akan memakai kredensial baru pada permintaan berikutnya.', ['time' => $client->credentials_rotated_at->format('d M Y H:i')]) }}
        </x-core::alert>
    @endif

    @php
        // Kelas select mengikuti `x-core::input` supaya dropdown tidak tampak
        // berbeda satu piksel dengan input di sebelahnya.
        $selectClass = 'block w-full py-3 px-4 text-gray-900 placeholder-gray-500 transition-all duration-200 bg-white border border-gray-300 form-input rounded-xl dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent disabled:cursor-not-allowed disabled:opacity-60';
    @endphp

    <form wire:submit="save" class="space-y-6">

        {{-- Identitas --}}
        <x-core::page-container>
            <h2 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Identitas') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-core::label :value="__('Nama')" for="nama" />
                    <x-core::input id="nama" type="text" wire:model="nama" />
                    <x-core::input-error for="nama" class="mt-1" />
                </div>

                {{-- 🔴 SLUG = `sumber` di naskah. Jadi readonly saat edit. --}}
                <div>
                    <x-core::label :value="__('Slug / Sumber')" for="slug" />
                    <x-core::input id="slug" type="text" wire:model="slug"
                        @readonly($client?->exists)
                        class="{{ $client?->exists ? 'cursor-not-allowed opacity-60' : '' }}" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if ($client?->exists)
                            {{ __('Tidak bisa diubah: nilai ini sudah tertulis permanen di naskah yang tercatat.') }}
                        @else
                            {{ __('Dipakai sebagai kolom sumber pada setiap naskah. Huruf kecil, angka, dan tanda hubung saja.') }}
                        @endif
                    </p>
                    <x-core::input-error for="slug" class="mt-1" />
                </div>
            </div>

            <div class="mt-4">
                <x-core::label :value="__('Device Wara')" for="waraClientId" />
                <select id="waraClientId" wire:model="waraClientId" class="{{ $selectClass }}">
                    <option value="">{{ __('Belum ditugaskan') }}</option>
                    @foreach ($waraClients as $option)
                        <option value="{{ $option['id'] }}" @disabled(! $option['available'])>
                            {{ $option['label'] }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Satu device hanya boleh dipakai satu client, supaya OTP tidak pernah masuk ke akun yang salah. Device yang sudah dipakai client lain tetap ditampilkan, hanya tidak bisa dipilih.') }}
                </p>
                <x-core::input-error for="waraClientId" class="mt-1" />
            </div>

            <div class="mt-4">
                <x-core::label :value="__('Purpose OTP')" for="otpPurpose" />
                <x-core::input id="otpPurpose" type="text" wire:model="otpPurpose" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Boleh dikosongkan. Dipakai untuk menempelkan state OTP ke tujuan tertentu di wara.') }}
                </p>
                <x-core::input-error for="otpPurpose" class="mt-1" />
            </div>
        </x-core::page-container>

        {{-- Kredensial --}}
        <x-core::page-container>
            <h2 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Kredensial') }}</h2>
            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Sentuh hanya kolom yang memang berubah. Kolom yang tidak dicentang akan menyimpan nilai lamanya apa adanya.') }}
            </p>

            <div class="space-y-3">
                <x-srikandi::secret-field name="username"
                    :label="__('Username Srikandi')" type="text"
                    :set="$secretSet['username'] ?? false"
                    :secret-actions="$secretActions"
                    :show-actions="$client?->exists ?? false" />

                <x-srikandi::secret-field name="password"
                    :label="__('Password Srikandi')"
                    :set="$secretSet['password'] ?? false"
                    :secret-actions="$secretActions"
                    :show-actions="$client?->exists ?? false" />

                <x-srikandi::secret-field name="totpSecret"
                    :label="__('TOTP Secret')"
                    :set="$secretSet['totpSecret'] ?? false"
                    :secret-actions="$secretActions"
                    :show-actions="$client?->exists ?? false"
                    :hint="__('Base32 dari aplikasi autentikator. Boleh dikosongkan kalau login tidak memakai TOTP.')" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-core::label :value="__('Jumlah Digit TOTP')" for="totpDigits" />
                    <x-core::input id="totpDigits" type="number" min="6" max="8" wire:model="totpDigits" />
                    <x-core::input-error for="totpDigits" class="mt-1" />
                </div>

                <div>
                    <x-core::label :value="__('Periode TOTP (detik)')" for="totpPeriod" />
                    <x-core::input id="totpPeriod" type="number" min="15" max="90" wire:model="totpPeriod" />
                    <x-core::input-error for="totpPeriod" class="mt-1" />
                </div>
            </div>

            <div class="mt-4 space-y-3">
                <x-srikandi::secret-field name="geminiApiKey"
                    :label="__('Gemini API Key')"
                    :set="$secretSet['geminiApiKey'] ?? false"
                    :secret-actions="$secretActions"
                    :show-actions="$client?->exists ?? false"
                    :hint="__('Dipakai untuk solving captcha. Boleh dikosongkan kalau captcha tidak diaktifkan.')" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-core::label :value="__('Model Gemini')" for="geminiModel" />
                    <x-core::input id="geminiModel" type="text" wire:model="geminiModel" />
                    <x-core::input-error for="geminiModel" class="mt-1" />
                </div>

                <div>
                    <x-core::label :value="__('Model Gemini Cadangan')" for="geminiFallbackModel" />
                    <x-core::input id="geminiFallbackModel" type="text" wire:model="geminiFallbackModel" />
                    <x-core::input-error for="geminiFallbackModel" class="mt-1" />
                </div>
            </div>
        </x-core::page-container>

        {{-- Nomor akun --}}
        <x-core::page-container>
            <h2 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Nomor Akun') }}</h2>
            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Nomor akun Srikandi: tempat kode OTP dari Srikandi diterima. Notifikasi OTP gagal atau kedaluwarsa juga dikirim ke nomor ini (S5.1). Bukan tujuan notifikasi naskah baru — itu dikirim ke daftar penerima di grup Notifikasi Naskah Baru. Boleh ditulis lokal atau internasional; bentuk bakunya disimpan sama.') }}
            </p>

            <x-srikandi::secret-field name="phone"
                :label="__('Nomor Akun')" type="tel"
                :set="$secretSet['phone'] ?? false"
                :secret-actions="$secretActions"
                :show-actions="$client?->exists ?? false" />

            @if ($client?->exists)
                <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <x-core::secondary-button
                        type="button"
                        wire:click="testOtpNotification"
                        wire:loading.attr="disabled"
                        wire:target="testOtpNotification"
                        label="{{ __('Kirim uji notifikasi OTP') }}" />

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Uji kirim ke Nomor Akun di atas — bukan ke penerima naskah.') }}
                    </p>
                </div>
            @endif
        </x-core::page-container>

        {{-- Notifikasi Naskah Baru --}}
        <x-core::page-container>
            <h2 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Notifikasi Naskah Baru') }}</h2>

            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <x-core::checkbox wire:model="notifyEnabled" />
                <span>{{ __('Kirim notifikasi saat ada naskah baru masuk') }}</span>
            </label>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-core::label :value="__('Saluran')" for="notifyVia" />
                    <select id="notifyVia" wire:model="notifyVia" @disabled(! $notifyEnabled) class="{{ $selectClass }}">
                        <option value="none">{{ __('Tidak ada') }}</option>
                        <option value="whatsapp">{{ __('WhatsApp') }}</option>
                    </select>
                    <x-core::input-error for="notifyVia" class="mt-1" />
                </div>

                <div>
                    <x-core::label :value="__('Purpose Notifikasi')" for="notifyPurpose" />
                    <x-core::input id="notifyPurpose" type="text" wire:model="notifyPurpose" />
                    <x-core::input-error for="notifyPurpose" class="mt-1" />
                </div>
            </div>

            {{-- Penerima: satu baris = satu nomor. Menggantikan kolom tunggal
                 `notify_phone`. Nomor tersimpan ditampilkan tersamar; nomor
                 baru diketik di baris tambah di bawah. --}}
            <div class="mt-6 border-t border-gray-200 pt-4 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Penerima Notifikasi') }}</h3>
                <p class="mb-4 mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Notifikasi OTP gagal atau kedaluwarsa dikirim ke Nomor Akun, bukan ke daftar ini. Satu notifikasi teragregasi dikirim ke setiap penerima aktif, boleh lebih dari satu. Nomor yang sudah tersimpan ditampilkan tersamar dan tidak bisa diubah di sini — hapus lalu tambahkan lagi bila perlu.') }}
                </p>

                <div class="space-y-2">
                    @forelse ($recipients as $index => $recipient)
                        <div wire:key="recipient-{{ $recipient['id'] ?? 'baru-'.$index }}"
                            class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                            <div class="min-w-0 flex-1">
                                <span class="font-mono text-sm text-gray-800 dark:text-gray-200">
                                    {{ $recipient['value_masked'] }}
                                </span>
                                @if (($recipient['id'] ?? null) === null)
                                    <x-core::badge color="blue" :label="__('baru')" class="ml-2" />
                                @endif
                            </div>

                            <div class="w-48">
                                <x-core::input type="text"
                                    wire:model="recipients.{{ $index }}.label"
                                    placeholder="{{ __('Label (opsional)') }}" />
                            </div>

                            <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                                <x-core::checkbox wire:model="recipients.{{ $index }}.is_active" />
                                {{ __('Aktif') }}
                            </label>

                            <x-core::danger-button
                                type="button"
                                wire:click="removeRecipient({{ $index }})"
                                label="{{ __('Hapus') }}" />
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Belum ada penerima. Tanpa penerima aktif, tidak ada notifikasi yang dikirim.') }}
                        </p>
                    @endforelse
                </div>

                <div class="mt-4 rounded-xl border border-dashed border-gray-300 p-3 dark:border-gray-600">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-56 flex-1">
                            <x-core::label :value="__('Nomor penerima')" for="newRecipientPhone" />
                            <x-core::input id="newRecipientPhone" type="tel" wire:model="newRecipientPhone"
                                placeholder="0812... / 62812..." />
                        </div>

                        <div class="min-w-56 flex-1">
                            <x-core::label :value="__('Label')" for="newRecipientLabel" />
                            <x-core::input id="newRecipientLabel" type="text" wire:model="newRecipientLabel"
                                placeholder="{{ __('mis. Admin') }}" />
                        </div>

                        <x-core::secondary-button
                            type="button"
                            wire:click="addRecipient"
                            label="{{ __('Tambah penerima') }}" />
                    </div>

                    <x-core::input-error for="newRecipientPhone" class="mt-2" />
                    <x-core::input-error for="newRecipientLabel" class="mt-1" />

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Penerima baru tersimpan bersamaan dengan tombol Simpan di bawah.') }}
                    </p>
                </div>

                @if ($client?->exists)
                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <x-core::secondary-button
                            type="button"
                            wire:click="testNaskahNotification"
                            wire:loading.attr="disabled"
                            wire:target="testNaskahNotification"
                            label="{{ __('Kirim uji notifikasi naskah') }}" />

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Uji kirim ke SEMUA penerima aktif di daftar — bukan ke Nomor Akun.') }}
                        </p>
                    </div>
                @endif
            </div>
        </x-core::page-container>

        {{-- Status --}}
        <x-core::page-container>
            <h2 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Status') }}</h2>

            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <x-core::checkbox wire:model="isActive" />
                <span>{{ __('Aktif dipakai scraper') }}</span>
            </label>

            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Client tidak bisa dihapus dari sini. Matikan status ini untuk mencabut akses tanpa merusak riwayat naskah dan login yang sudah tercatat.') }}
            </p>
        </x-core::page-container>

        <div class="flex items-center justify-end gap-3">
            <x-core::secondary-button
                link
                href="{{ route('srikandi.client.index') }}"
                label="{{ __('Batal') }}" />

            <x-core::button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="save"
                label="{{ __('Simpan') }}" />
        </div>
    </form>
</div>
