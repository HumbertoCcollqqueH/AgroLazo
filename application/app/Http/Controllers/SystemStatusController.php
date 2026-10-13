<?php

namespace App\Http\Controllers;

use App\Services\SystemHealthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SystemStatusController extends Controller
{
    public function __invoke(SystemHealthService $health): View|RedirectResponse
    {
        if (! app()->environment(['local', 'testing']) && request()->routeIs('home')) {
            return to_route('marketplace.index');
        }
        abort_unless(app()->environment(['local', 'testing']), 404);

        return view('system-status', [
            'checks' => $health->snapshot(),
            'checkedAt' => now()->timezone('America/Lima')->format('d/m/Y H:i:s'),
            'milestones' => config('agroconecta.milestones'),
        ]);
    }
}
