<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class DeliveryAddress
{
    public static function rules(): array
    {
        return [
            'delivery_address' => 'required|array:recipient,phone,department,province,district,street,reference,latitude,longitude',
            'delivery_address.recipient' => 'required|string|min:3|max:160',
            'delivery_address.phone' => ['required', 'string', 'regex:/\A\+?[1-9][0-9]{8,14}\z/'],
            'delivery_address.department' => 'required|string|min:3|max:100',
            'delivery_address.province' => 'required|string|min:3|max:100',
            'delivery_address.district' => 'required|string|min:3|max:100',
            'delivery_address.street' => 'required|string|min:10|max:500',
            'delivery_address.reference' => 'required|string|min:5|max:500',
            'delivery_address.latitude' => 'required|numeric|between:-90,90',
            'delivery_address.longitude' => 'required|numeric|between:-180,180',
        ];
    }

    public static function validate(array $data): array
    {
        return Validator::make($data, self::rules())->validate()['delivery_address'];
    }

    public function update(User $actor, Order $order, array $data): Order
    {
        $commerce = app(Commerce::class);

        return $commerce->transaction($actor, null, function (User $actor) use ($commerce, $order, $data): Order {
            $commerce->authorizeOrder($actor, $order);
            $data = Validator::make($data, ['key' => 'required|uuid', 'version' => 'required|integer|min:1', ...self::rules()])->validate();
            $data['order_id'] = $order->id;

            return $commerce->once($actor, 'delivery-address', $data, function () use ($commerce, $actor, $order, $data): Order {
                $order = app(CheckoutService::class)->lockOrder($order);
                Commerce::ensure($order->version === (int) $data['version'], 'version', 'La compra cambió. Actualiza la página antes de guardar.');
                Commerce::ensure(in_array($order->status, ['confirmed', 'collecting', 'ready'], true) && ! Shipment::query()->where('order_id', $order->id)->exists(), 'delivery_address', 'La dirección no puede cambiar después de programar un despacho. Contacta con coordinación.');
                $before = $order->delivery_address;
                $order->update(['delivery_address' => $data['delivery_address'], 'version' => $order->version + 1]);
                $commerce->event($actor, 'delivery_address_updated', $order, $order->quote->lot, ['before' => $before, 'after' => $data['delivery_address']]);

                return $order;
            });
        });
    }
}
