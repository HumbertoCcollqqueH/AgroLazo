<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceFilterRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class HomeController extends Controller
{
    public function __invoke(MarketplaceFilterRequest $request, MarketplaceController $marketplace): View|RedirectResponse
    {
        if ($request->user()?->hasPermission('view-operations')) {
            return to_route('dashboard');
        }

        return $marketplace->index($request);
    }
}
