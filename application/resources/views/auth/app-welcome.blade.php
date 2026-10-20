<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#153f2d">
    <title>Bienvenido · AgroLazo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-welcome">
    <main class="app-welcome-shell">
        <section class="app-welcome-landscape" aria-label="El campo nos une">
            <img src="{{ asset('images/marketplace/andean-fields-768.webp') }}" alt="Campos de cultivo en los Andes peruanos" fetchpriority="high" width="768" height="512">
            <a class="ac-brand" href="{{ route('mobile.home') }}"><span class="ac-brand-symbol"><x-ac-icon name="brand" /></span><span>AgroLazo<small>EL CAMPO NOS UNE</small></span></a>
            <div class="app-welcome-caption"><span>DE NUESTRA TIERRA</span><p>Cultivamos oportunidades.<br>Conectamos personas.</p></div>
        </section>
        <section class="app-welcome-content">
            <p class="pp-kicker">BIENVENIDO A AGROLAZO</p>
            <h1>El campo, más<br><em>cerca de ti.</em></h1>
            <p>Ofrece tu cosecha o encuentra productos para tu negocio. Coordina cada paso desde un mismo lugar.</p>
            <div class="app-welcome-benefits"><span><x-ac-icon name="leaf" />Origen directo</span><span><x-ac-icon name="shield" />Ofertas verificadas</span></div>
            <a class="pp-button" href="{{ route('login') }}">Ingresar a mi cuenta <x-ac-icon name="arrow" /></a>
            <a class="pp-button pp-button-light" href="{{ route('register') }}">Crear cuenta de comprador o productor</a>
            <a class="app-welcome-explore" href="{{ route('marketplace.index') }}">Primero quiero explorar productos →</a>
            <p class="app-welcome-team">¿Eres parte del equipo? Ingresa con tu cuenta de administración o coordinación.</p>
        </section>
    </main>
</body>
</html>
