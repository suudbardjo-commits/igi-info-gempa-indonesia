<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0A1630">
    <meta name="description" content="IGI (Info Gempa Indonesia): gempa terbaru dari BMKG dan USGS, peta sebaran, posko pengungsian, dan panduan keselamatan.">
    <meta property="og:site_name" content="IGI - Info Gempa Indonesia">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="alternate" type="application/atom+xml" title="IGI: gempa terbaru" href="/feed.xml">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="IGI">
    <title inertia>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
