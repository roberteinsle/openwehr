<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wachen-Monitor – {{ app(\App\Services\OrganizationSettings::class)->shortName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/monitor.js'])
    @livewireStyles
</head>
<body class="h-full bg-[#0b1433] text-white antialiased">
    <livewire:monitor.board />
    @livewireScripts
    <script>
        // Full reload every 6 hours: picks up new deployments and keeps a long-running screen fresh
        setTimeout(() => location.reload(), 6 * 60 * 60 * 1000);

        // Alarm check every 10 seconds, whatever the refresh interval: a new, changed or ended alarm
        // re-renders the board at once (data-alarm-state holds what is shown now)
        setInterval(async () => {
            try {
                const response = await fetch(@js($statusUrl), { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (! response.ok) return;
                const { state } = await response.json();
                const shown = document.querySelector('[data-alarm-state]')?.dataset.alarmState ?? '';
                if (state !== shown) window.Livewire?.first()?.$refresh();
            } catch (e) { /* offline for a moment: try again next time */ }
        }, 10000);
    </script>
</body>
</html>
