@php
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $entry = $manifest['resources/ts/main.tsx'] ?? null;
    $basePath = trim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $baseUrl = $basePath ? "/{$basePath}" : '';
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AutoSurvey</title>
    <meta name="description" content="学術論文RSS集約・AI要約システム">
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#4f46e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="AutoSurvey">
    <link rel="manifest" href="{{ $baseUrl }}/manifest.json">
    <link rel="icon" href="{{ $baseUrl }}/favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="{{ $baseUrl }}/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="{{ $baseUrl }}/icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;600;700&display=swap" rel="stylesheet">
    @if($entry)
        @if(isset($entry['css']))
            @foreach($entry['css'] as $css)
                <link rel="stylesheet" href="{{ $baseUrl }}/build/{{ $css }}">
            @endforeach
        @endif
        <script type="module" src="{{ $baseUrl }}/build/{{ $entry['file'] }}"></script>
    @endif
</head>
<body>
    <div id="root"></div>
    <script>
        // Register Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('{{ $baseUrl }}/sw.js', { scope: '{{ $baseUrl }}/' })
                    .then(function(registration) {
                        console.log('ServiceWorker registered:', registration.scope);
                    })
                    .catch(function(error) {
                        console.log('ServiceWorker registration failed:', error);
                    });
            });
        }
    </script>
</body>
</html>
