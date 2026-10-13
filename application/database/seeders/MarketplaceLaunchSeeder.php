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
use App\Models\User;
use App\Services\AggregationService;
use App\Services\OfferRegistry;
use App\Services\ProducerRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MarketplaceLaunchSeeder extends Seeder
{
    public const BATCH = 'catalogo-2026-10-06';

    public const DISTRIBUTION = ['papa' => 8, 'quinua' => 8, 'canihua' => 3, 'oca' => 3, 'cebada-grano' => 3, 'avena-grano' => 3, 'trigo' => 2];

    public static function key(int $index): string
    {
        return sprintf('51000600-2026-4000-8000-%012d', $index);
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('commerce.live_enabled')) {
            throw new RuntimeException('Esta carga solo se permite en local/testing con comercio real desactivado.');
        }
        DB::transaction(function (): void {
            $staff = User::where('active', true)->whereHas('roles', fn ($q) => $q->where('code', 'coordinator'))->orderBy('id')->firstOrFail();
            $registry = app(OfferRegistry::class);
            $aggregation = app(AggregationService::class);
            $puno = Location::where('type', 'department')->where('name', 'Puno')->firstOrFail();
            $places = [];
            foreach ([['San Román', 'Juliaca'], ['San Román', 'Cabana'], ['San Román', 'Cabanillas'], ['Huancané', 'Taraco'], ['El Collao', 'Ilave'], ['Azángaro', 'Azángaro']] as [$provinceName, $districtName]) {
                $province = Location::firstOrCreate(['parent_id' => $puno->id, 'type' => 'province', 'name' => $provinceName]);
                $places[] = Location::firstOrCreate(['parent_id' => $province->id, 'type' => 'district', 'name' => $districtName]);
            }
            $names = ['Lucía', 'Mateo', 'Elena', 'Raúl', 'Rosa', 'Andrés', 'Marina', 'Tomás', 'Carmen', 'Víctor'];
            $families = ['Quispe', 'Condori', 'Apaza', 'Huanca', 'Flores', 'Chura'];
            $varieties = ['papa' => ['Amarilla', 'Canchán', 'Huayro', 'Imilla'], 'quinua' => ['Blanca', 'Roja', 'Negra'], 'canihua' => ['Parda', 'Amarilla'], 'oca' => ['Amarilla', 'Rosada'], 'cebada-grano' => ['Grano entero'], 'avena-grano' => ['Grano entero'], 'trigo' => ['Grano entero']];
            $prices = ['papa' => '1.80', 'quinua' => '7.50', 'canihua' => '8.20', 'oca' => '2.40', 'cebada-grano' => '1.60', 'avena-grano' => '1.90', 'trigo' => '2.10'];
            $from = now('America/Lima')->toDateString();
            $until = now('America/Lima')->addDays(14)->toDateString();
            $index = 0;
            foreach (self::DISTRIBUTION as $slug => $count) {
                $product = Product::where('slug', $slug)->where('active', true)->firstOrFail();
                for ($variant = 0; $variant < $count; $variant++) {
                    $index++;
                    if (Lot::where('source_key', self::key($index))->exists()) {
                        continue;
                    }
                    $place = $places[$variant % count($places)];
                    $varietyName = $varieties[$slug][$variant % count($varieties[$slug])];
                    $variety = ProductVariety::firstOrCreate(['product_id' => $product->id, 'name' => $varietyName], ['code' => Str::slug($varietyName)]);
                    $quality = QualityGrade::firstOrCreate(['product_id' => $product->id, 'name' => $variant % 2 === 0 ? 'Primera' : 'Segunda'], ['code' => $variant % 2 === 0 ? 'primera' : 'segunda']);
                    $price = bcadd($prices[$slug], bcmul((string) $variant, '0.15', 6), 6);
                    $portions = [];
                    for ($member = 0; $member < 2; $member++) {
                        $number = ($index - 1) * 2 + $member;
                        $producer = Producer::create([
                            'full_name' => $names[$number % 10].' '.$families[intdiv($number, 10)].' '.$families[($number + 2) % 6],
                            'dni' => sprintf('8000%04d', $number), 'phone' => '+1202555'.sprintf('%04d', 100 + $number),
                            'type' => $member === 0 ? 'family' : 'individual', 'status' => 'pending_verification',
                            'registered_by' => $staff->id, 'seed_batch' => self::BATCH,
                        ]);
                        $producer->products()->attach($product);
                        $producer->locations()->create(['location_id' => $place->id, 'community' => $place->name, 'recorded_by' => $staff->id, 'valid_from' => now()]);
                        $producer = app(ProducerRegistry::class)->transition($staff, $producer, ['result' => 'verified', 'method' => 'administrative', 'notes' => 'Registro ficticio de la carga autorizada '.self::BATCH.'; no acredita una visita ni identidad real.']);
                        Conversation::create(['provider' => 'fake', 'account' => 'local-simulator', 'channel' => 'whatsapp', 'phone' => $producer->phone, 'phone_lookup' => $producer->phone_lookup, 'producer_id' => $producer->id, 'state' => 'READY', 'context' => ['seed_batch' => self::BATCH], 'consent' => 'granted', 'notice_version' => self::BATCH, 'consented_at' => now()]);
                        $quantity = (string) (60 + $variant * 35 + $member * 25 + ($index % 4) * 20);
                        $offer = $registry->create($staff, ['source_key' => self::key(1000 + $number), 'producer_id' => $producer->id, 'product_id' => $product->id, 'variety_id' => $variety->id, 'quality_grade_id' => $quality->id, 'location_id' => $place->id, 'quantity' => $quantity, 'unit_id' => $product->base_unit_id, 'available_from' => $from, 'available_until' => $until, 'context' => 'Cosecha seleccionada, entrega coordinada.', 'reason' => 'Carga autorizada '.self::BATCH, 'notes' => 'Datos generados; importes ilustrativos, sin cotización real de mercado.']);
                        $offer = $registry->transition($staff, $offer, ['version' => $offer->version, 'result' => 'verified', 'reason' => 'Revisión administrativa de composición y disponibilidad de la carga '.self::BATCH]);
                        $agreement = $registry->proposeAgreement($staff, $offer, ['version' => $offer->version, 'gross_unit_price' => $price, 'producer_unit_cost' => '0.000000', 'terms' => 'Precio por kg. Transporte organizado y cubierto por AgroLazo. Peso aceptado sujeto a recepción.', 'valid_until' => $until]);
                        $registry->decideAgreement($staff, $offer, $agreement, ['version' => $offer->version, 'decision' => 'accepted', 'evidence' => 'Aceptación simulada autorizada para la carga '.self::BATCH.'; no corresponde a consentimiento de una persona real.']);
                        $offer = $registry->transition($staff, $offer, ['version' => $offer->version, 'result' => 'published', 'reason' => 'Publicación de carga autorizada '.self::BATCH]);
                        $portions[] = ['offer_id' => $offer->id, 'offer_version' => $offer->version, 'agreement_id' => $agreement->id, 'quantity' => $quantity];
                    }
                    $lot = $aggregation->create($staff, ['source_key' => self::key($index), 'portions' => $portions, 'reason' => 'Agrupación de dos aportaciones compatibles; carga '.self::BATCH]);
                    $aggregation->transition($staff, $lot, ['version' => $lot->version, 'result' => 'published', 'reason' => 'Catálogo publicado por solicitud administrativa; carga '.self::BATCH]);
                }
            }
            AuditLog::record($staff, 'catalog.bulk_population', $staff, ['batch' => self::BATCH, 'lots' => 30, 'producers' => 60, 'available_from' => $from, 'available_until' => $until], 'Población explícita autorizada por administración, con productores ficticios y canal local.');
        });
    }
}
