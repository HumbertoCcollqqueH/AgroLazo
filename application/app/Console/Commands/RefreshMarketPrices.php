<?php

namespace App\Console\Commands;

use App\Services\AutomatedMarketPriceProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('prices:refresh')]
#[Description('Consulta precios oficiales conservando variedad, fecha, unidad y fuente.')]
class RefreshMarketPrices extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AutomatedMarketPriceProvider $prices): int
    {
        if (! config('market-pricing.enabled')) {
            $this->info('La consulta automática está desactivada.');

            return self::SUCCESS;
        }
        try {
            $snapshot = $prices->refresh();
            $this->info('Fuente consultada: '.count($snapshot['prices']).' variedades. La antigüedad se valida antes de proponer cada precio.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se pudo actualizar la fuente de mercado. No se inventarán precios.');

            return self::FAILURE;
        }
    }
}
