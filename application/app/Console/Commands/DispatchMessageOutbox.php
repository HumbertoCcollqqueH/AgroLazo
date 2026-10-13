<?php

namespace App\Console\Commands;

use App\Services\Messaging\OutboxDispatcher;
use Illuminate\Console\Command;

class DispatchMessageOutbox extends Command
{
    protected $signature = 'messaging:dispatch {--limit=100}';

    protected $description = 'Despacha eventos durables de mensajería a la cola configurada';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatch(max(1, min(1000, (int) $this->option('limit'))));
        $this->info($count.' eventos enviados a la cola.');

        return self::SUCCESS;
    }
}
