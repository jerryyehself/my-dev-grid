<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>{{ env('APP_NAME') }}</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Luxurious+Roman&family=Playwrite+NZ+Guides&family=Story+Script&family=Playfair+Display:ital,wght@0,400..900;1,400..900&family=Merriweather:wght@400;700&family=Roboto:wght@400;700&display=swap"
        rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="overflow-hidden flex flex-col">
    <div id="app" class="flex flex-col h-screen relative">
    </div>
</body>

</html>
