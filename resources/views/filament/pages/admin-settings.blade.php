<x-filament-panels::page>
 <x-filament::section>
  <dl class="space-y-4">
   @foreach ($this->settings() as $key => $value)
    <div><dt class="font-medium">{{ \App\Support\Presentation::label($key) }}</dt><dd>{{ \App\Support\Presentation::label($value) }}</dd></div>
   @endforeach
  </dl>
 </x-filament::section>
</x-filament-panels::page>
