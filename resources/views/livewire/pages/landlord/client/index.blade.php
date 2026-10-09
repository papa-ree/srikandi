<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Client Srikandi') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Akun yang dipakai scraper untuk masuk dan mengambil OTP. Kredensial tidak pernah ditampilkan kembali di halaman ini.') }}
            </p>
        </div>

        <a href="{{ route('srikandi.client.create') }}"
            class="flex-none rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
            {{ __('Client Baru') }}
        </a>
    </div>

    <livewire:srikandi.pages.landlord.client.section.client-table />
</div>