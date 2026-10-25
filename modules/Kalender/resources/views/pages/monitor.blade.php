<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Termin-Monitor – {{ app(\App\Services\OrganizationSettings::class)->shortName() }}</title>
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="h-full bg-[#0b1433] text-white antialiased">
    <livewire:kalender.monitor-board />
    @livewireScripts
    <script>
        // Full reload every 6 hours: picks up new deployments and keeps a long-running screen fresh
        setTimeout(() => location.reload(), 6 * 60 * 60 * 1000);
    </script>
</body>
</html>
