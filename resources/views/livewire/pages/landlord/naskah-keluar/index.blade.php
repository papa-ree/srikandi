<div class="space-y-6">
    <div>
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
            {{ __('Naskah Keluar Srikandi') }}
        </h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ __('Salinan list naskah keluar yang dibaca scraper. Read-only — naskah asli tetap milik Srikandi.') }}
        </p>
    </div>

    <livewire:srikandi.pages.landlord.naskah-keluar.section.naskah-keluar-header />
    <livewire:srikandi.pages.landlord.naskah-keluar.section.naskah-keluar-table />
</div>
