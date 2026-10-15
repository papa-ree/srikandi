<div class="space-y-6">
    <div>
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
            {{ __('Log Sesi Srikandi') }}
        </h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ __('Laporan diagnostik tiap sesi panen scraper — berapa baris dibaca, apakah cursor habis, dan berapa yang baru/revisi. Read-only.') }}
        </p>
    </div>

    <livewire:srikandi.pages.landlord.sesi.section.sesi-header />
    <livewire:srikandi.pages.landlord.sesi.section.sesi-table />
</div>
