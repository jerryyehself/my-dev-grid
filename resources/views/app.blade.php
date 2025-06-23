<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>{{ env('APP_NAME') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-stone-300 h-screen overflow-hidden px-4 py-3">
    <main id="app" class="grid grid-cols-4 gap-3 h-full min-h-0 box-border">
    </main>
</body>

</html>
