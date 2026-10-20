<x-layout title="Elige tu cuenta">
    <section class="mx-auto max-w-4xl py-5">
        <header class="mb-8 text-center">
            <p class="pp-kicker">TU LUGAR EN AGROLAZO</p>
            <h1 class="mt-3 text-3xl font-semibold">¿Cómo quieres participar?</h1>
            <p class="mt-4">Elige tu cuenta. Podrás realizar tus gestiones desde la web y la aplicación.</p>
        </header>
        <div class="pp-two">
            <article class="pp-card pp-form">
                <x-ac-icon name="box" />
                <h2>Soy comprador</h2>
                <p>Busco productos para mi restaurante, hotel, negocio u hogar.</p>
                <p>Explora lotes, cotiza cantidades, indica dónde y cuándo recibirlos y sigue tus pedidos.</p>
                <p class="pp-info">El equipo revisará tu perfil antes de habilitar compras.</p>
                <a class="pp-button" href="{{ route('register', ['type' => 'buyer']) }}">Crear cuenta de comprador →</a>
            </article>
            <article class="pp-card pp-form">
                <x-ac-icon name="leaf" />
                <h2>Soy productor</h2>
                <p>Quiero ofrecer mi cosecha y coordinar el recojo de mis productos.</p>
                <p>Registra tus cultivos y ubicación, envía ofertas, acepta precios y consulta tus ventas y pagos.</p>
                <p class="pp-info">Coordinación verifica tu ficha y tus ofertas. Tus productos se publican después de la revisión y de que aceptes las condiciones.</p>
                <a class="pp-button" href="{{ route('producer.register') }}">Crear cuenta de productor →</a>
            </article>
        </div>
        <p class="mt-7 text-center">¿Ya tienes cuenta? <a class="pp-link" href="{{ route('login') }}">Ingresar</a></p>
        <p class="mt-4 text-center text-sm">Si ya tienes una ficha de productor, podrás solicitar su vinculación desde tu cuenta y conservar tu historial.</p>
    </section>
</x-layout>
