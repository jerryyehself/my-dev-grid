<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>{{ env('APP_NAME') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="overflow-hidden flex flex-col">
    <header
        class="h-20 bg-gradient-to-b from-stone-900 to-stone-700 text-stone-200 grid grid-cols-4 items-center p-2 box-border shadow-black shadow-sm relative z-10">
        <h1 class="col-span-1 xl:text-4xl md:text-2xl font-bold relative inline-block font-serif">
            <a href="/" class="block p-3">My Dev Grid</a>
        </h1>
        <nav class="col-span-2 flex space-x-20 text-stone-300 font-medium justify-center xl:text-xl md:text-md">
            <a href="/"
                class="relative inline-block px-1 after:content-[''] after:absolute after:left-0 after:bottom-0 after:h-[2px] after:w-0 after:bg-white after:transition-all after:duration-300 hover:after:w-full hover:text-white">
                首頁
            </a>
            <a href="/triple-control"
                class="relative inline-block px-1 after:content-[''] after:absolute after:left-0 after:bottom-0 after:h-[2px] after:w-0 after:bg-white after:transition-all after:duration-300 hover:after:w-full hover:text-white">
                管理
            </a>
        </nav>
    </header>
    <div id="app" class="flex flex-col">
    </div>
</body>

</html>
