<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Services\Messaging\ProducerPriceFlow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('prices:propose {--offer= : Revisar solo esta oferta verificada}')]
#[Description('Propone precios de mercado vigentes al productor para confirmar y publicar')]
class ProposeOfferPrices extends Command
{
    public function handle(ProducerPriceFlow $flow): int
    {
        $id = $this->option('offer');
        if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Indica un identificador de oferta válido.');

            return self::FAILURE;
        }
        $queued = 0;
        $query = Offer::query()->where('status', 'verified')->when($id, fn ($query) => $query->whereKey($id))->orderBy('id');
        $query->chunkById(100, function ($offers) use ($flow, &$queued): void {
            foreach ($offers as $offer) {
                try {
                    $message = $flow->queue($offer);
                    if ($message?->wasRecentlyCreated) {
                        $queued++;
                    }
                } catch (ValidationException) {
                    $this->warn('La oferta #'.$offer->id.' necesita revisión antes de proponer o publicar.');
                }
            }
        });
        $this->info('Mensajes nuevos de precio o clasificación en cola: '.$queued.'.');

        return self::SUCCESS;
    }
}
