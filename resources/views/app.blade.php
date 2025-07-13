<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>{{ env('APP_NAME') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="overflow-hidden flex flex-col">
    @include('nav')
    <div id="app" class="flex flex-col">
    </div>
</body>

</html>
