<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceFilterRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function mobile(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            if ($request->user()->hasPermission('view-operations')) {
                return to_route('marketplace.index');
            }

            return to_route($request->user()->landingRoute());
        }

        return view('auth.app-welcome');
    }

    public function __invoke(MarketplaceFilterRequest $request, MarketplaceController $marketplace): View|RedirectResponse
    {
        if ($request->user()?->hasRole('producer') && ! $request->user()->hasPermission('view-operations')) {
            return to_route('producer.home');
        }
        if ($request->user()?->hasPermission('view-operations')) {
            return to_route('dashboard');
        }

        return $marketplace->index($request);
    }
}
