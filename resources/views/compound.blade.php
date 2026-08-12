<x-layouts.compound :links="$links">
    <x-slot:world>
        <x-world :payload="$compound"
                 heading="The Compound"
                 :subheading="config('lgu.name')"
                 :corner-href="route('dashboard')"
                 corner-label="Dashboard"
                 corner-icon="home" />
    </x-slot:world>
</x-layouts.compound>
