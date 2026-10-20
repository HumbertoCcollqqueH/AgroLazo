<details class="native-account-menu" data-native-account-menu>
    <summary aria-label="Mi cuenta y cerrar sesión" title="Mi cuenta"><x-ac-icon name="user" /><span class="sr-only">Mi cuenta</span></summary>
    <div class="native-account-panel">
        <strong>{{ auth()->user()->name }}</strong>
        <small>{{ auth()->user()->email }}</small>
        <a href="{{ route('account.edit') }}"><x-ac-icon name="user" />Mi perfil y seguridad</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><x-ac-icon name="logout" />Cerrar sesión</button></form>
    </div>
</details>
