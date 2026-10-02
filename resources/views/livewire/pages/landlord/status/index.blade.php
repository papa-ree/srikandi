<div>
    <x-core::breadcrumb :active="__('Status Srikandi')" />

    <div class="mt-6">
        {{--
            Judul, subjudul, dan tanggal pembaruan dirender oleh komponennya
            sendiri. Halaman ini sengaja tidak membuat header sendiri supaya
            judul tidak tampil dua kali.
        --}}
        <x-core::checklist :data="$status" />
    </div>
</div>
