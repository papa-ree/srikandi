<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $client?->exists ? __('Edit Client Srikandi') : __('Client Srikandi Baru') }}
            </h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Akun yang dipakai scraper untuk masuk ke Srikandi dan mengambil OTP.') }}
            </p>
        </div>

        <a href="{{ route('srikandi.client.index') }}"
            class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            {{ __('Kembali ke daftar') }}
        </a>
    </div>

    {{-- Kredensial tidak pernah kembali ke browser. --}}
    <x-core::alert variant="warning"
        :title="__('Kredensial tidak pernah ditampilkan lagi')">
        {{ __('Isi kolom hanya menampilkan "terisi" atau "belum diisi". Nilai yang sudah tersimpan tidak pernah dikirim ke halaman ini. Untuk mengganti atau menghapus, centang aksi yang sesuai lalu isi kolomnya.') }}
    </x-core::alert>

    @if ($client?->exists && $client->credentials_rotated_at !== null)
        <x-core::alert variant="info" :title="__('Kredensial terakhir diganti')">
            {{ __('Rotasi terakhir: :time. Setelah rotasi, scraper akan memakai kredensial baru pada permintaan berikutnya.', ['time' => $client->credentials_rotated_at->format('d M Y H:i')]) }}
        </x-core::alert>
    @endif

    <form wire:submit="save" class="space-y-6">

        {{-- Identitas --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Identitas') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="nama" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Nama') }}
                    </label>
                    <input id="nama" type="text" wire:model="nama"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    @error('nama')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- 🔴 SLUG = `sumber` di naskah. Jadi readonly saat edit. --}}
                <div>
                    <label for="slug" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Slug / Sumber') }}
                    </label>
                    <input id="slug" type="text" wire:model="slug"
                        @readonly($client?->exists)
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if ($client?->exists)
                            {{ __('Tidak bisa diubah: nilai ini sudah tertulis permanen di naskah yang tercatat.') }}
                        @else
                            {{ __('Dipakai sebagai kolom sumber pada setiap naskah. Huruf kecil, angka, dan tanda hubung saja.') }}
                        @endif
                    </p>
                    @error('slug')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4">
                <label for="waraClientId" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Device Wara') }}
                </label>
                <select id="waraClientId" wire:model="waraClientId"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
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
                @error('waraClientId')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-4">
                <label for="otpPurpose" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Purpose OTP') }}
                </label>
                <input id="otpPurpose" type="text" wire:model="otpPurpose"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Boleh dikosongkan. Dipakai untuk menempelkan state OTP ke tujuan tertentu di wara.') }}
                </p>
                @error('otpPurpose')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Kredensial --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Kredensial') }}</h2>
            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Sentuh hanya kolom yang memang berubah. Kolom yang tidak dicentang akan menyimpan nilai lamanya apa adanya.') }}
            </p>

            <div class="space-y-3">
                <x-srikandi::secret-field name="username"
                    :label="__('Username Srikandi')" type="text"
                    :set="$secretSet['username'] ?? false"
                    :show-actions="$client?->exists ?? false" />

                <x-srikandi::secret-field name="password"
                    :label="__('Password Srikandi')"
                    :set="$secretSet['password'] ?? false"
                    :show-actions="$client?->exists ?? false" />

                <x-srikandi::secret-field name="totpSecret"
                    :label="__('TOTP Secret')"
                    :set="$secretSet['totpSecret'] ?? false"
                    :show-actions="$client?->exists ?? false"
                    :hint="__('Base32 dari aplikasi autentikator. Boleh dikosongkan kalau login tidak memakai TOTP.')" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="totpDigits" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Jumlah Digit TOTP') }}
                    </label>
                    <input id="totpDigits" type="number" min="6" max="8" wire:model="totpDigits"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    @error('totpDigits')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="totpPeriod" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Periode TOTP (detik)') }}
                    </label>
                    <input id="totpPeriod" type="number" min="15" max="90" wire:model="totpPeriod"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    @error('totpPeriod')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4 space-y-3">
                <x-srikandi::secret-field name="geminiApiKey"
                    :label="__('Gemini API Key')"
                    :set="$secretSet['geminiApiKey'] ?? false"
                    :show-actions="$client?->exists ?? false"
                    :hint="__('Dipakai untuk solving captcha. Boleh dikosongkan kalau captcha tidak diaktifkan.')" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="geminiModel" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Model Gemini') }}
                    </label>
                    <input id="geminiModel" type="text" wire:model="geminiModel"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    @error('geminiModel')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="geminiFallbackModel" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Model Gemini Cadangan') }}
                    </label>
                    <input id="geminiFallbackModel" type="text" wire:model="geminiFallbackModel"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    @error('geminiFallbackModel')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Nomor tujuan --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Nomor Tujuan') }}</h2>
            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Nomor yang menerima OTP dari Srikandi. Boleh ditulis lokal atau internasional; bentuk bakunya disimpan sama.') }}
            </p>

            <x-srikandi::secret-field name="phone"
                :label="__('Nomor HP')" type="tel"
                :set="$secretSet['phone'] ?? false"
                :show-actions="$client?->exists ?? false" />
        </div>

        {{-- Notifikasi --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Notifikasi') }}</h2>

            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" wire:model="notifyEnabled"
                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900">
                <span>{{ __('Kirim notifikasi kalau OTP gagal atau kedaluwarsa') }}</span>
            </label>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="notifyVia" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Saluran') }}
                    </label>
                    <select id="notifyVia" wire:model="notifyVia"
                        @disabled(! $notifyEnabled)
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <option value="none">{{ __('Tidak ada') }}</option>
                        <option value="whatsapp">{{ __('WhatsApp') }}</option>
                    </select>
                    @error('notifyVia')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="notifyPurpose" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Purpose Notifikasi') }}
                    </label>
                    <input id="notifyPurpose" type="text" wire:model="notifyPurpose"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                    @error('notifyPurpose')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4">
                <x-srikandi::secret-field name="notifyPhone"
                    :label="__('Nomor Notifikasi')" type="tel"
                    :set="$secretSet['notifyPhone'] ?? false"
                    :show-actions="$client?->exists ?? false"
                    :hint="__('Boleh dikosongkan bila sama dengan nomor tujuan.')" />
            </div>
        </div>

        {{-- Status --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Status') }}</h2>

            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" wire:model="isActive"
                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900">
                <span>{{ __('Aktif dipakai scraper') }}</span>
            </label>

            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Client tidak bisa dihapus dari sini. Matikan status ini untuk mencabut akses tanpa merusak riwayat naskah dan login yang sudah tercatat.') }}
            </p>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('srikandi.client.index') }}"
                class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                {{ __('Batal') }}
            </a>

            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                {{ __('Simpan') }}
            </button>
        </div>
    </form>
</div>