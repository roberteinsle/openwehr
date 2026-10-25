@props(['at', 'allDay' => false])
{{-- Days, hours on the day itself, minutes in the last 59 minutes until $at; updates itself every 30 seconds (App\Support\Countdown) --}}
@php($at = \Illuminate\Support\Carbon::instance($at))
<span {{ $attributes->merge(['class' => 'inline-block rounded-full bg-fw-navy px-2.5 py-0.5 text-xs font-semibold text-white']) }}
      x-data="{
          at: {{ $at->getTimestampMs() }}, allDay: @js((bool) $allDay), text: @js(\App\Support\Countdown::label($at, (bool) $allDay)),
          day(ms) { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(ms)) },
          tick() {
              const now = Date.now();
              const days = Math.round((Date.parse(this.day(this.at)) - Date.parse(this.day(now))) / 86400000);
              if (days >= 1) { this.text = 'noch ' + days + (days === 1 ? ' Tag' : ' Tage'); return }
              if (this.allDay || this.at <= now) { this.text = this.at <= now && ! this.allDay ? 'läuft' : 'heute'; return }
              const minutes = Math.ceil((this.at - now) / 60000);
              this.text = minutes >= 60 ? 'noch ' + Math.floor(minutes / 60) + ' Std.' : 'noch ' + minutes + ' Min.';
          },
      }"
      x-init="tick(); setInterval(() => tick(), 30000)" x-text="text">{{ \App\Support\Countdown::label($at, (bool) $allDay) }}</span>
