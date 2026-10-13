<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'manage-users' => 'Administrar usuarios y roles',
            'view-operations' => 'Consultar operación',
            'view-buyer-area' => 'Consultar área del comprador',
            'approve-buyers' => 'Autorizar compradores',
            'confirm-payments' => 'Confirmar cobros',
            'place-orders' => 'Comprar con perfil autorizado',
            'view-catalogs' => 'Consultar catálogos y equivalencias',
            'manage-catalogs' => 'Administrar catálogos',
            'view-producers' => 'Consultar fichas de productores',
            'manage-producers' => 'Registrar y actualizar productores',
            'verify-producers' => 'Verificar productores y resolver su estado',
            'view-offers' => 'Consultar ofertas y condiciones',
            'manage-offers' => 'Registrar y verificar ofertas',
            'manage-messaging' => 'Atender conversaciones y probar mensajes',
            'view-lots' => 'Consultar lotes y su composición privada',
            'manage-lots' => 'Agrupar ofertas y publicar lotes',
            'manage-commerce' => 'Coordinar compras, acopio y logística',
            'manage-pricing' => 'Registrar reglas y referencias de precios',
            'manage-traceability' => 'Publicar y revocar trazabilidad pública',
        ];

        foreach ($permissions as $code => $name) {
            Permission::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        }

        $roles = [
            'admin' => ['Administrador', array_keys($permissions)],
            'coordinator' => ['Coordinador', ['view-operations', 'view-catalogs', 'view-producers', 'manage-producers', 'verify-producers', 'view-offers', 'manage-offers', 'manage-messaging', 'view-lots', 'manage-lots', 'manage-commerce', 'manage-traceability']],
            'buyer' => ['Comprador', ['view-buyer-area', 'place-orders']],
        ];

        foreach ($roles as $code => [$name, $grants]) {
            $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $name]);
            $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('code', $grants)->pluck('id'));
        }
    }
}
