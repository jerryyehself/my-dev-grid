<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>{{ env('APP_NAME') }}</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&display=swap">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="overflow-hidden flex flex-col">
    <div id="app" class="flex flex-col">
    </div>
</body>

</html>
