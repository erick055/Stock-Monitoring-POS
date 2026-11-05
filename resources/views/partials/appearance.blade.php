<link rel="icon" href="{{ asset('favicon.ico') }}?v=1" sizes="32x32">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=1" sizes="any">
<script>
    try { document.documentElement.dataset.theme = localStorage.getItem('motosync-theme') === 'light' ? 'light' : 'dark'; }
    catch (_) { document.documentElement.dataset.theme = 'dark'; }
</script>
@vite(['resources/css/appearance.css', 'resources/js/appearance.js'])
<noscript><style>[data-history-panel][hidden]{display:block!important}</style></noscript>
