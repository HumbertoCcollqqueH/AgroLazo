<?php

namespace App\Http\Controllers;

use App\Models\ProducerPayoutProfile;
use App\Services\ProducerPayoutProfiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProducerPayoutProfileController extends Controller
{
    public function save(Request $request, ProducerPayoutProfiles $profiles): RedirectResponse
    {
        $profiles->save($request->user(), $request->all(), $request->file('qr'));

        return to_route('producer.profile')->with('status', 'Tus datos de cobro están guardados. AgroLazo los usará para tus liquidaciones.');
    }

    public function qr(Request $request, ProducerPayoutProfile $profile, ProducerPayoutProfiles $profiles): StreamedResponse
    {
        abort_unless($profiles->canView($request->user(), $profile->producer), 404);
        abort_unless($profile->qr_path && in_array($profile->qr_mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($profile->qr_path), 404);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$profile->qr_mime];

        return $disk->response($profile->qr_path, 'Yape-QR-v'.$profile->version.'.'.$extension, [
            'Content-Type' => $profile->qr_mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }
}
