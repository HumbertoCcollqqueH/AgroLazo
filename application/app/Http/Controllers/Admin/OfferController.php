<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveOfferRequest;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\OfferPhoto;
use App\Models\OfferPriceAgreement;
use App\Models\Producer;
use App\Models\Product;
use App\Models\ProductVariety;
use App\Models\QualityGrade;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\AutomatedMarketPriceProvider;
use App\Services\LotAvailability;
use App\Services\Messaging\ProducerPriceFlow;
use App\Services\OfferPublication;
use App\Services\OfferRegistry;
use App\Services\OfferReview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfferController extends Controller
{
    public function __construct(private OfferRegistry $registry) {}

    public function index(Request $request): View
    {
        Gate::authorize('view-offers');
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(Offer::STATUSES))], 'product_id' => 'nullable|integer|exists:products,id']);
        $offers = Offer::query()->with(['producer', 'product', 'baseUnit', 'location'])->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))->latest('id')->paginate(15)->withQueryString();

        return view('offers.index', ['offers' => $offers, 'products' => Product::query()->orderBy('name')->get()]);
    }

    public function create(): View
    {
        Gate::authorize('manage-offers');

        return $this->form(null);
    }

    public function edit(Offer $offer): View
    {
        Gate::authorize('manage-offers');
        abort_unless($offer->canBeEdited(), 403);

        return $this->form($offer);
    }

    public function store(SaveOfferRequest $request): RedirectResponse
    {
        $offer = $this->registry->create($request->user(), $request->validated());

        return to_route('offers.show', $offer)->with('status', 'Oferta registrada. Queda pendiente de verificación.');
    }

    public function update(SaveOfferRequest $request, Offer $offer): RedirectResponse
    {
        $offer = $this->registry->update($request->user(), $offer, $request->validated());

        return to_route('offers.show', $offer)->with('status', 'Nueva versión guardada. Requiere revisión y condiciones de esta versión.');
    }

    public function show(Offer $offer): View
    {
        Gate::authorize('view-offers');
        $offer->load(['producer', 'product', 'variety', 'qualityGrade', 'location', 'unit', 'baseUnit', 'photos', 'histories.actor', 'agreements' => fn ($q) => $q->orderByDesc('version')]);

        $availability = app(LotAvailability::class);
        $visibleLots = $availability->marketplace()->whereHas('portions', fn ($query) => $query->where('offer_id', $offer->id))->get();
        $relatedLots = Lot::query()->whereHas('portions', fn ($query) => $query->where('offer_id', $offer->id))->latest('id')->get();
        $latestAgreement = $offer->agreements->firstWhere('offer_version', $offer->version);
        $activeAgreement = $offer->activeAgreement();
        $freeQuantity = $availability->ungroupedQuantity($offer);
        $canPublishBalance = in_array($offer->status, ['verified', 'published'], true)
            && $offer->available_until->toDateString() >= now('America/Lima')->toDateString()
            && $activeAgreement !== null && bccomp($freeQuantity, '0', 3) > 0;

        return view('offers.show', [
            'offer' => $offer, 'transitions' => OfferRegistry::TRANSITIONS[$offer->status],
            'visibleLots' => $visibleLots, 'relatedLots' => $relatedLots,
            'latestAgreement' => $latestAgreement, 'activeAgreement' => $activeAgreement,
            'freeQuantity' => $freeQuantity, 'canPublishBalance' => $canPublishBalance,
            'marketReference' => app(AutomatedMarketPriceProvider::class)->forOffer($offer),
        ]);
    }

    public function publish(Request $request, Offer $offer, OfferPublication $publication): RedirectResponse
    {
        $lot = $publication->publish($request->user(), $offer, $request->all());

        return to_route('offers.show', $offer)->with('status', 'Publicado para compradores en el lote '.$lot->code.'.');
    }

    public function transition(Request $request, Offer $offer): RedirectResponse
    {
        if ((config('platform.internal_communication') || $request->has('price_mode')) && $request->input('result') === 'verified' && $offer->status !== 'published') {
            $agreement = app(OfferReview::class)->approve($request->user(), $offer, $request->all());

            return back()->with('status', $agreement
                ? 'Revisión completada y precio enviado al productor dentro de AgroLazo. Cuando acepte, su cantidad se publicará y agrupará automáticamente; no necesitas otra aprobación.'
                : 'Datos aprobados. No encontramos una referencia de precio vigente compatible. Completa el precio aquí para que el productor pueda aceptar y publicar; no necesita otra revisión de datos.');
        }
        $offer = $this->registry->transition($request->user(), $offer, $request->all());
        if ($offer->status === 'verified') {
            try {
                $message = app(ProducerPriceFlow::class)->queue($offer);
                if ($message) {
                    return back()->with('status', 'Oferta verificada. Se preparó la propuesta de precio para el productor.');
                }
            } catch (\Throwable $exception) {
                report($exception);

                return back()->with('status', 'Oferta verificada. La propuesta de precio está pendiente; el asistente reintentará automáticamente.');
            }
        }

        return back()->with('status', 'Decisión registrada en el historial.');
    }

    public function propose(Request $request, Offer $offer): RedirectResponse
    {
        $this->registry->proposeAgreement($request->user(), $offer, $request->all());

        return back()->with('status', 'Precio enviado al productor. Al aceptarlo, su oferta se publicará automáticamente.');
    }

    public function decide(Request $request, Offer $offer, OfferPriceAgreement $agreement): RedirectResponse
    {
        app(OfferPublication::class)->decide($request->user(), $offer, $agreement, $request->all());

        return back()->with('status', $request->input('decision') === 'accepted'
            ? 'Aceptación registrada y cantidad publicada para compradores. La agrupación compatible se resolvió automáticamente.'
            : 'El productor no aceptó este precio. La oferta sigue guardada; acuerden otra propuesta desde Conversaciones.');
    }

    public function upload(Request $request, Offer $offer): RedirectResponse
    {
        Gate::authorize('manage-offers');
        $data = $request->validate(['photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120', 'caption' => 'nullable|string|max:500']);
        $file = $data['photo'];
        abort_unless(@getimagesize($file->getRealPath()) !== false, 422, 'La imagen no es válida.');
        $path = $file->store('offers/'.$offer->id, 'local');
        abort_unless(is_string($path), 503, 'No se pudo guardar la foto.');
        try {
            DB::transaction(function () use ($request, $offer, $file, $path, $data): void {
                Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
                Gate::forUser(User::query()->findOrFail($request->user()->id))->authorize('manage-offers');
                $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
                abort_if(in_array($offer->status, ['cancelled', 'expired'], true), 403);
                $photo = $offer->photos()->create(['uploaded_by' => $request->user()->id, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'caption' => $data['caption'] ?? null]);
                AuditLog::record($request->user(), 'offer.photo_added', $offer, ['photo_id' => $photo->id], 'private_evidence');
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('status', 'Foto guardada como evidencia privada.');
    }

    public function photo(Offer $offer, OfferPhoto $photo): StreamedResponse
    {
        Gate::authorize('view-offers');
        abort_unless($photo->offer_id === $offer->id, 404);

        return Storage::disk('local')->download($photo->path, 'oferta-'.$offer->id.'-foto-'.$photo->id.'.'.match ($photo->mime) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        }, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function form(?Offer $offer): View
    {
        return view('offers.form', [
            'offer' => $offer,
            'producers' => Producer::query()->whereNotIn('status', ['suspended', 'archived', 'rejected'])->orderBy('full_name')->get(),
            'products' => Product::query()->orderBy('name')->get()->filter->isUsable(),
            'varieties' => ProductVariety::query()->where('active', true)->with('product')->orderBy('name')->get(),
            'qualities' => QualityGrade::query()->where('active', true)->with('product')->orderBy('name')->get(),
            'locations' => Location::query()->with('parent')->orderBy('name')->get()->filter->isUsable(),
            'units' => Unit::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }
}
