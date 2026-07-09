<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'SlotFlow') }}</title>

    <meta name="description" content="Appointment booking for small service businesses. Say what you need in plain English and pick a time that is genuinely free.">
    <meta property="og:title" content="{{ config('app.name') }}">
    <meta property="og:type" content="website">
    <meta property="og:description" content="Appointment booking for small service businesses.">

    {{--
        Set the theme before the first paint. Doing this in a Vue lifecycle
        hook means a white flash on every load for anyone using dark mode.
    --}}
    <script>
        (() => {
            const stored = localStorage.getItem('slotflow-theme');
            const dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="h-full font-sans antialiased">
    @inertia
</body>
</html>
