<x-layouts.compound :office-count="$officeCount">
    <x-slot:world>
        {{-- No corner buttons: the layout puts every control in one dock along
             the bottom of the map instead. --}}
        <x-world :payload="$compound"
                 heading="The Compound"
                 :subheading="config('lgu.name')"
                 scene="compound"
                 :controls="false"
                 :sign-in="route('login')" />
    </x-slot:world>
</x-layouts.compound>
