<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Location;
use App\Models\Lot;
use App\Models\Producer;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Role;
use App\Models\User;
use App\Services\AggregationService;
use App\Services\CollectionService;
use App\Services\DeliveryAddress;
use App\Services\OfferRegistry;
use App\Services\ProducerRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RegionalCollectionCatalogSeeder extends Seeder
{
    public const BATCH = 'acopio-regional-2026-10-10';

    public const DISTRIBUTION = ['papa' => 10, 'quinua' => 10, 'canihua' => 4, 'oca' => 4, 'cebada-grano' => 3, 'avena-grano' => 3, 'trigo' => 2];

    /** Public settlement coordinates are anchors; individual parcels and businesses are generated. */
    private const PLACES = [
        ['province' => 'San Román', 'district' => 'Juliaca', 'community' => 'Collana Choja', 'latitude' => '-15.533365', 'longitude' => '-70.155748'],
        ['province' => 'San Román', 'district' => 'Cabana', 'community' => 'Huaychulaya', 'latitude' => '-15.686838', 'longitude' => '-70.272932'],
        ['province' => 'San Román', 'district' => 'Cabanillas', 'community' => 'Tincopalca', 'latitude' => '-15.858708', 'longitude' => '-70.752822'],
        ['province' => 'Huancané', 'district' => 'Taraco', 'community' => 'Tuni Requena', 'latitude' => '-15.327372', 'longitude' => '-69.900951'],
        ['province' => 'El Collao', 'district' => 'Ilave', 'community' => 'Jaquemase', 'latitude' => '-16.207739', 'longitude' => '-69.655619'],
        ['province' => 'Azángaro', 'district' => 'Azángaro', 'community' => 'Segundo Jilahuata', 'latitude' => '-14.952426', 'longitude' => '-70.098443'],
    ];

    public const BUYERS = [
        ['name' => 'Hotel Mirador del Altiplano', 'email' => 'mirador.altiplano@agrolazo.test', 'type' => 'hotel', 'place' => 0],
        ['name' => 'Restaurante Mesa Andina', 'email' => 'mesa.andina@agrolazo.test', 'type' => 'restaurant', 'place' => 0],
        ['name' => 'Hotel Amanecer de Cabana', 'email' => 'amanecer.cabana@agrolazo.test', 'type' => 'hotel', 'place' => 1],
        ['name' => 'Restaurante Sabores de Cabana', 'email' => 'sabores.cabana@agrolazo.test', 'type' => 'restaurant', 'place' => 1],
        ['name' => 'Comedor Valle de Cabanillas', 'email' => 'valle.cabanillas@agrolazo.test', 'type' => 'company', 'place' => 2],
        ['name' => 'Restaurante Fogón de Taraco', 'email' => 'fogon.taraco@agrolazo.test', 'type' => 'restaurant', 'place' => 3],
        ['name' => 'Hotel Brisas de Ilave', 'email' => 'brisas.ilave@agrolazo.test', 'type' => 'hotel', 'place' => 4],
        ['name' => 'Restaurante Cocina de Azángaro', 'email' => 'cocina.azangaro@agrolazo.test', 'type' => 'restaurant', 'place' => 5],
        ['name' => 'Panadería Grano de Altura', 'email' => 'grano.altura@agrolazo.test', 'type' => 'processor', 'place' => 0],
        ['name' => 'Catering Encuentro Andino', 'email' => 'encuentro.andino@agrolazo.test', 'type' => 'company', 'place' => 3],
    ];

    public static function key(int $index): string
    {
        return sprintf('51000900-2026-4000-8000-%012d', $index);
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('commerce.live_enabled')) {
            throw new RuntimeException('Esta carga solo se permite en local/testing con comercio real desactivado.');
        }
        $password = getenv('AGROLAZO_POPULATION_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Define AGROLAZO_POPULATION_PASSWORD con al menos 12 caracteres; conserva la contraseña fuera del código.');
        }
        DB::transaction(function () use ($password): void {
            Role::where('code', 'admin')->lockForUpdate()->firstOrFail();
            $admin = User::where('active', true)->whereHas('roles', fn ($q) => $q->where('code', 'admin'))->orderBy('id')->firstOrFail();
            $staff = User::where('active', true)->whereHas('roles', fn ($q) => $q->where('code', 'coordinator'))->orderBy('id')->firstOrFail();
            $createdBuyers = $this->buyers($admin, $password);
            $places = $this->locations();
            $registry = app(OfferRegistry::class);
            $aggregation = app(AggregationService::class);
            $varieties = ['papa' => ['Amarilla', 'Canchán', 'Huayro', 'Imilla'], 'quinua' => ['Blanca', 'Roja', 'Negra'], 'canihua' => ['Parda', 'Amarilla'], 'oca' => ['Amarilla', 'Rosada'], 'cebada-grano' => ['Grano entero'], 'avena-grano' => ['Grano entero'], 'trigo' => ['Grano entero']];
            $prices = ['papa' => '1.80', 'quinua' => '7.50', 'canihua' => '8.20', 'oca' => '2.40', 'cebada-grano' => '1.60', 'avena-grano' => '1.90', 'trigo' => '2.10'];
            $from = now('America/Lima')->toDateString();
            $until = now('America/Lima')->addDays(30)->toDateString();
            $index = 0;
            $createdLots = 0;
            $createdProducers = 0;
            foreach (self::DISTRIBUTION as $slug => $count) {
                $product = Product::where('slug', $slug)->where('active', true)->firstOrFail();
                for ($variant = 0; $variant < $count; $variant++) {
                    $index++;
                    if (Lot::where('source_key', self::key($index))->exists()) {
                        continue;
                    }
                    $placeIndex = ($index - 1) % count($places);
                    $place = $places[$placeIndex];
                    $varietyName = $varieties[$slug][$variant % count($varieties[$slug])];
                    $variety = ProductVariety::firstOrCreate(['product_id' => $product->id, 'name' => $varietyName], ['code' => Str::slug($varietyName)]);
                    $quality = QualityGrade::firstOrCreate(['product_id' => $product->id, 'name' => $variant % 2 === 0 ? 'Primera' : 'Segunda'], ['code' => $variant % 2 === 0 ? 'primera' : 'segunda']);
                    $price = bcadd($prices[$slug], bcmul((string) $variant, '0.15', 6), 6);
                    $members = 2 + (($index - 1) % 5);
                    $portions = [];
                    for ($member = 0; $member < $members; $member++) {
                        $number = ($index - 1) * 6 + $member;
                        $producer = $this->producer($staff, $product, $place, $placeIndex, $number, $member, $index);
                        $quantity = (string) (60 + $variant * 25 + $member * 20 + ($index % 4) * 15);
                        $offer = $registry->create($staff, [
                            'source_key' => self::key(1000 + $number), 'producer_id' => $producer->id, 'product_id' => $product->id,
                            'variety_id' => $variety->id, 'quality_grade_id' => $quality->id, 'location_id' => $place->id,
                            'quantity' => $quantity, 'unit_id' => $product->base_unit_id, 'available_from' => $from, 'available_until' => $until,
                            'context' => 'Cosecha seleccionada. Recojo coordinado en el lugar registrado.',
                            'reason' => 'Carga autorizada '.self::BATCH, 'notes' => 'Datos generados; precios ilustrativos, sin cotización real de mercado.',
                        ]);
                        $offer = $registry->transition($staff, $offer, ['version' => $offer->version, 'result' => 'verified', 'reason' => 'Revisión de composición, ubicación y disponibilidad de la carga '.self::BATCH]);
                        $agreement = $registry->proposeAgreement($staff, $offer, ['version' => $offer->version, 'gross_unit_price' => $price, 'producer_unit_cost' => '0.000000', 'terms' => 'Precio por kg. Transporte organizado y cubierto por AgroLazo. Peso aceptado sujeto a recepción.', 'valid_until' => $until]);
                        $registry->decideAgreement($staff, $offer, $agreement, ['version' => $offer->version, 'decision' => 'accepted', 'evidence' => 'Aceptación simulada autorizada para '.self::BATCH.'; no corresponde a consentimiento de una persona real.']);
                        $offer = $registry->transition($staff, $offer, ['version' => $offer->version, 'result' => 'published', 'reason' => 'Publicación de carga autorizada '.self::BATCH]);
                        $portions[] = ['offer_id' => $offer->id, 'offer_version' => $offer->version, 'agreement_id' => $agreement->id, 'quantity' => $quantity];
                        $createdProducers++;
                    }
                    $lot = $aggregation->create($staff, ['source_key' => self::key($index), 'portions' => $portions, 'reason' => 'Agrupación de '.$members.' aportaciones compatibles y próximas; carga '.self::BATCH]);
                    $aggregation->transition($staff, $lot, ['version' => $lot->version, 'result' => 'published', 'reason' => 'Catálogo autorizado para revisión de acopio regional; carga '.self::BATCH]);
                    $createdLots++;
                }
            }
            $this->collectionPoints($staff, $places);
            if ($createdLots > 0 || $createdBuyers > 0) {
                AuditLog::record($admin, 'catalog.bulk_population', $admin, ['batch' => self::BATCH, 'lots' => $createdLots, 'producers' => $createdProducers, 'buyers' => $createdBuyers, 'available_from' => $from, 'available_until' => $until], 'Carga ficticia autorizada: identidades, parcelas, empresas y precios generados; mensajería de productores aislada del canal real.');
            }
        });
    }

    /** @return list<Location> */
    private function locations(): array
    {
        $puno = Location::where('type', 'department')->where('name', 'Puno')->firstOrFail();
        $places = [];
        foreach (self::PLACES as $place) {
            $province = Location::firstOrCreate(['parent_id' => $puno->id, 'type' => 'province', 'name' => $place['province']]);
            $places[] = Location::firstOrCreate(['parent_id' => $province->id, 'type' => 'district', 'name' => $place['district']]);
        }

        return $places;
    }

    private function producer(User $staff, Product $product, Location $place, int $placeIndex, int $number, int $member, int $lotIndex): Producer
    {
        $names = ['Adela', 'Benjamín', 'Clara', 'Diego', 'Emilia', 'Fabián', 'Gloria', 'Héctor', 'Irene', 'Julián', 'Katia', 'Lorenzo'];
        $families = ['Quispe', 'Condori', 'Apaza', 'Huanca', 'Flores', 'Chura', 'Mamani', 'Choque', 'Pacori', 'Ccama', 'Callata', 'Suca'];
        $reference = self::PLACES[$placeIndex];
        $producer = Producer::create([
            'full_name' => $names[$number % 12].' '.$families[intdiv($number, 12) % 12].' '.$families[($number + 3) % 12],
            'dni' => sprintf('8100%04d', $number), 'phone' => '+447700900'.sprintf('%03d', $number),
            'type' => $member % 2 === 0 ? 'family' : 'individual', 'status' => 'pending_verification',
            'registered_by' => $staff->id, 'seed_batch' => self::BATCH,
        ]);
        $producer->products()->attach($product);
        $producer->locations()->create([
            'location_id' => $place->id, 'community' => $reference['community'],
            'address' => 'Comunidad '.$reference['community'].', parcela '.sprintf('%03d', $number + 1).'. Acceso por camino vecinal; portón de la parcela junto al desvío señalizado.',
            'latitude' => number_format((float) $reference['latitude'] + ($member - 2.5) * 0.003 + intdiv($lotIndex - 1, 6) * 0.0001, 6, '.', ''),
            'longitude' => number_format((float) $reference['longitude'] + ($member % 2 === 0 ? -1 : 1) * 0.002 + intdiv($lotIndex - 1, 6) * 0.0001, 6, '.', ''),
            'recorded_by' => $staff->id, 'valid_from' => now(),
        ]);
        $registry = app(ProducerRegistry::class);
        $producer = $registry->recordConsent($staff, $producer, ['purpose' => 'location_planning', 'notice_version' => self::BATCH, 'decision' => 'granted']);
        $producer = $registry->transition($staff, $producer, ['result' => 'verified', 'method' => 'administrative', 'notes' => 'Registro ficticio autorizado de '.self::BATCH.'; no acredita identidad, visita de campo ni consentimiento real.']);
        Conversation::create(['provider' => 'fake', 'account' => 'local-simulator', 'channel' => 'whatsapp', 'phone' => $producer->phone, 'phone_lookup' => $producer->phone_lookup, 'producer_id' => $producer->id, 'state' => 'READY', 'context' => ['seed_batch' => self::BATCH], 'consent' => 'granted', 'notice_version' => self::BATCH, 'consented_at' => now()]);

        return $producer;
    }

    private function buyers(User $admin, string $password): int
    {
        $role = Role::where('code', 'buyer')->firstOrFail();
        $created = 0;
        foreach (self::BUYERS as $index => $data) {
            if ($existing = User::where('email', $data['email'])->first()) {
                if (! AuditLog::where('action', 'catalog.buyer_population')->where('subject_type', $existing->getMorphClass())->where('subject_id', $existing->id)->where('changes->batch', self::BATCH)->exists()) {
                    throw new RuntimeException('La cuenta '.$data['email'].' ya existe fuera de esta carga. No se modificó.');
                }

                continue;
            }
            $place = self::PLACES[$data['place']];
            $phone = '+447700900'.(800 + $index);
            $address = DeliveryAddress::validate(['delivery_address' => [
                'recipient' => $data['name'], 'phone' => $phone, 'department' => 'Puno', 'province' => $place['province'], 'district' => $place['district'],
                'street' => 'Comunidad '.$place['community'].', vía principal '.(100 + $index * 10).', local de '.$data['name'].'.',
                'reference' => 'Entrada de abastecimiento por la vía principal. Contactar a recepción antes de descargar.',
                'latitude' => number_format((float) $place['latitude'] + 0.015 + ($index % 3) * 0.002, 6, '.', ''),
                'longitude' => number_format((float) $place['longitude'] + 0.010, 6, '.', ''),
            ]]);
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $password]);
            $user->forceFill(['active' => true, 'email_verified_at' => now()])->save();
            $user->roles()->attach($role);
            $buyer = $user->buyer()->create(['type' => $data['type'], 'phone' => $phone, 'delivery_address' => $address]);
            $buyer->forceFill(['status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => now()])->save();
            AuditLog::record($admin, 'buyer.approved', $buyer, ['before' => 'pending', 'after' => 'approved'], 'Cuenta ficticia de usuario final para carga administrativa '.self::BATCH);
            AuditLog::record($admin, 'catalog.buyer_population', $user, ['batch' => self::BATCH], 'Empresa y dirección generadas; no representan una cuenta comercial real.');
            $created++;
        }

        return $created;
    }

    /** @param list<Location> $places */
    private function collectionPoints(User $staff, array $places): void
    {
        $unit = Product::where('slug', 'papa')->firstOrFail()->base_unit_id;
        foreach (self::PLACES as $index => $data) {
            app(CollectionService::class)->point($staff, [
                'key' => self::key(2000 + $index), 'name' => 'Acopio '.$data['community'],
                'description' => 'Punto de planificación de la carga '.self::BATCH.'. Ubicación generada; no acredita un centro operativo verificado.',
                'address' => 'Comunidad '.$data['community'].', explanada junto al acceso principal, '.$data['district'].'.',
                'location_id' => $places[$index]->id, 'latitude' => $data['latitude'], 'longitude' => $data['longitude'],
                'capacity' => '10000', 'base_unit_id' => $unit, 'opens_at' => '06:00', 'closes_at' => '18:00',
            ]);
        }
    }
}
