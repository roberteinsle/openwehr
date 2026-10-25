@props(['label'])
{{-- For which Abteilung a Termin or Dienst is, for Kameraden in several Abteilungen --}}
<span {{ $attributes->merge(['class' => 'inline-block rounded-full bg-fw-navy/10 px-2 py-0.5 text-xs font-semibold text-fw-navy']) }}>{{ $label }}</span>
