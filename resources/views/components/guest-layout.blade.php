@props(['title' => null])
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-900">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}Deploi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid h-full place-items-center px-4 font-sans antialiased">
    <div class="w-full max-w-sm">
        <div class="mb-8 flex items-center justify-center gap-2">
            <span class="grid size-10 place-items-center rounded-lg bg-indigo-500 text-lg font-bold text-white">D</span>
            <span class="text-2xl font-semibold tracking-tight text-white">Deploi</span>
        </div>
        <div class="card p-6">
            <h1 class="mb-4 text-lg font-semibold">{{ $title }}</h1>
            @if($errors->any())
                <div class="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</div>
            @endif
            {{ $slot }}
        </div>
    </div>
</body>
</html>
