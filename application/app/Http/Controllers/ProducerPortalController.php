<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CollectionEventItem;
use App\Models\CollectionReceipt;
use App\Models\Location;
use App\Models\Offer;
use App\Models\OfferPhoto;
use App\Models\OfferPriceAgreement;
use App\Models\OrderAllocation;
use App\Models\Producer;
use App\Models\ProducerSettlement;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Commerce;
use App\Services\OfferRegistry;
use App\Services\ProducerPortal;
use App\Services\ProducerRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProducerPortalController extends Controller
{
    public function register(): View
    {
        return view('producer.register');
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => 'required|string|min:3|max:150', 'email' => 'required|email|max:254|unique:users',
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(12)->mixedCase()->numbers()], 'accept' => 'accepted']);
        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create(Arr::only($data, ['name', 'email', 'password']));
            $user->roles()->attach(Role::query()->where('code', 'producer')->firstOrFail());
            AuditLog::record($user, 'producer.account_created', $user, [], 'self_registration');

            return $user->fresh();
        });
        Auth::login($user, str_contains($request->userAgent() ?? '', 'AgroLazoAndroid/'));
        $request->session()->regenerate();

        return to_route('producer.profile')->with('status', 'Tu cuenta está lista. Completa tu ficha o solicita vincular tu registro anterior desde Coordinación.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user()?->fresh();
        abort_unless($user?->active && $user->hasRole('producer'), 403);

        return $user;
    }

    public function home(Request $request): View
    {
        $user = $this->actor($request);
        $producer = $user->producer;
        $offers = $producer ? Offer::query()->where('producer_id', $producer->id)->with(['product', 'baseUnit', 'agreements'])->latest('id')->paginate(12) : null;

        return view('producer.home', compact('producer', 'offers'));
    }

    public function profile(Request $request): View
    {
        $producer = $this->actor($request)->producer;
        $products = Product::query()->where('active', true)->with(['baseUnit', 'category'])->orderBy('name')->get()->filter->isUsable();
        $locations = Location::query()->where('active', true)->with('parent')->orderBy('name')->get()->filter->isUsable();

        return view('producer.profile', compact('producer', 'products', 'locations'));
    }

    public function saveProfile(Request $request, ProducerRegistry $registry): RedirectResponse
    {
        $user = $this->actor($request);
        $request->validate(['location_consent' => 'nullable|boolean', 'product_ids' => 'required|array|min:1', 'profile_consent' => 'accepted']);
        DB::transaction(function () use ($request, $user, $registry): void {
            $data = [...$request->all(), 'product_ids' => $request->input('product_ids', [])];
            $producer = $user->producer ? $registry->updateOwnLocation($user, $data)
                : $registry->registerSelf($user, [...$data, 'full_name' => $user->name, 'email' => $user->email]);
            ProducerPortal::locationConsent($user, $producer, $request->boolean('location_consent'));
            ProducerPortal::team('Ficha de productor actualizada: '.$producer->full_name.'.', route('producers.show', $producer), 'profile:'.$producer->id.':'.now()->format('U.u'));
        });

        return to_route('producer.home')->with('status', 'Ficha guardada. Consulta aquí tu verificación y coordina cualquier cambio con el equipo.');
    }

    public function createOffer(Request $request): View|RedirectResponse
    {
        return $this->offerForm($request);
    }

    public function editOffer(Request $request, Offer $offer): View|RedirectResponse
    {
        abort_unless($this->actor($request)->producer?->id === $offer->producer_id, 404);
        abort_unless($offer->canBeEdited(), 403);
        $request->merge(['product_id' => $offer->product_id]);

        return $this->offerForm($request, $offer);
    }

    private function offerForm(Request $request, ?Offer $offer = null): View|RedirectResponse
    {
        $producer = $this->actor($request)->producer;
        if (! $producer?->canPublishOffers()) {
            return to_route('producer.home')->with('status', 'Completa tu ficha y espera su verificación antes de enviar ofertas.');
        }
        $products = Product::query()->where('active', true)->with(['baseUnit', 'category', 'varieties', 'qualityGrades'])->orderBy('name')->get()->filter->isUsable();
        $selected = $request->integer('product_id') ?: ($producer->products->count() === 1 ? $producer->products->first()->id : null);
        $product = $products->firstWhere('id', $selected);
        $locations = Location::query()->where('active', true)->with('parent')->orderBy('name')->get()->filter->isUsable();

        return view('producer.offer-form', compact('producer', 'products', 'product', 'locations', 'offer'));
    }

    public function storeOffer(Request $request, OfferRegistry $registry): RedirectResponse
    {
        $user = $this->actor($request);
        $request->validate(['confirmation' => 'accepted']);
        $offer = $registry->createOwn($user, $request->all());
        ProducerPortal::team('Nueva oferta #'.$offer->id.' enviada por '.$user->name.'.', route('offers.show', $offer), 'offer:'.$offer->id);

        return to_route('producer.offers.show', $offer)->with('status', 'Oferta enviada a revisión. Aquí recibirás el precio antes de publicarla.');
    }

    public function showOffer(Request $request, Offer $offer): View
    {
        abort_unless($this->actor($request)->producer?->id === $offer->producer_id, 404);
        $offer->load(['product', 'variety', 'qualityGrade', 'location', 'unit', 'baseUnit', 'photos', 'agreements' => fn ($q) => $q->latest('version'), 'histories']);

        return view('producer.offer', ['offer' => $offer, 'agreement' => $offer->agreements->firstWhere('offer_version', $offer->version)]);
    }

    public function updateOffer(Request $request, Offer $offer, OfferRegistry $registry): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->producer?->id === $offer->producer_id, 404);
        $request->validate(['confirmation' => 'accepted']);
        $registry->update($actor, $offer, [...$request->all(), 'reason' => 'Corrección enviada por el productor desde su cuenta.']);
        ProducerPortal::team('El productor corrigió la oferta #'.$offer->id.'. Requiere revisión.', route('offers.show', $offer), 'corrected:'.$offer->id.':'.$offer->fresh()->version);

        return to_route('producer.offers.show', $offer)->with('status', 'Corrección guardada y enviada a revisión.');
    }

    public function uploadPhoto(Request $request, Offer $offer): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->producer?->id === $offer->producer_id, 404);
        $data = $request->validate(['photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120', 'caption' => 'nullable|string|max:500']);
        $file = $data['photo'];
        abort_unless(@getimagesize($file->getRealPath()) !== false, 422);
        $path = $file->store('offers/'.$offer->id, 'local');
        abort_unless(is_string($path), 503);
        try {
            DB::transaction(function () use ($actor, $offer, $file, $path, $data): void {
                $actor = $actor->fresh();
                $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
                abort_unless($actor->active && $actor->hasRole('producer') && $actor->producer?->id === $offer->producer_id, 403);
                Commerce::ensure(! in_array($offer->status, ['cancelled', 'expired'], true) && $offer->photos()->count() < 8, 'photo', 'La oferta debe estar activa y admite hasta ocho fotos.');
                $photo = $offer->photos()->create(['uploaded_by' => $actor->id, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'caption' => $data['caption'] ?? null]);
                AuditLog::record($actor, 'offer.photo_added', $offer, ['photo_id' => $photo->id], 'self_service');
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('status', 'Foto añadida para la revisión del equipo.');
    }

    public function photo(Request $request, Offer $offer, OfferPhoto $photo): StreamedResponse
    {
        abort_unless($this->actor($request)->producer?->id === $offer->producer_id && $photo->offer_id === $offer->id, 404);

        return Storage::disk('local')->response($photo->path, null, ['Content-Type' => $photo->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function decide(Request $request, Offer $offer, OfferPriceAgreement $agreement, ProducerPortal $portal): RedirectResponse
    {
        $user = $this->actor($request);
        abort_unless($user->producer?->id === $offer->producer_id && $agreement->offer_id === $offer->id, 404);
        $data = $request->validate(['version' => 'required|integer|min:1', 'decision' => ['required', Rule::in(['accepted', 'rejected'])]]);
        $portal->decide($user, $offer, $agreement, $data);

        return back()->with('status', $data['decision'] === 'accepted' ? 'Precio aceptado y oferta publicada. Te avisaremos aquí cuando se compre tu aportación.' : 'Tu oferta está guardada sin publicar. Solicita otra propuesta desde Conversaciones; el equipo recibió tu respuesta.');
    }

    public function sales(Request $request): View
    {
        $id = $this->actor($request)->producer?->id;
        $allocations = OrderAllocation::query()->whereHas('portion.offer', fn ($q) => $q->where('producer_id', $id ?? 0))
            ->with(['portion.offer.product', 'portion.offer.baseUnit', 'orderItem.order'])->latest('id')->paginate(15);
        $pickups = CollectionEventItem::query()->whereHas('allocation.portion.offer', fn ($q) => $q->where('producer_id', $id ?? 0))
            ->with(['event.point', 'event.order', 'allocation.portion.offer.product'])->latest('id')->limit(30)->get();
        $settlements = ProducerSettlement::query()->where('producer_id', $id ?? 0)->with(['order', 'payouts'])->latest('id')->limit(30)->get();

        $receipts = CollectionReceipt::query()->whereHas('allocation.portion.offer', fn ($q) => $q->where('producer_id', $id ?? 0))
            ->with(['allocation.portion.offer.product', 'allocation.portion.offer.baseUnit', 'event.order'])->latest('id')->limit(30)->get();

        return view('producer.sales', compact('allocations', 'pickups', 'settlements', 'receipts'));
    }

    public function linkAccount(Request $request, Producer $producer): RedirectResponse
    {
        Gate::authorize('manage-users');
        $data = $request->validate(['email' => 'required|email|max:254', 'reason' => 'required|string|min:10|max:500', 'identity_confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $producer, $data): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('manage-users');
            $producer = Producer::query()->lockForUpdate()->findOrFail($producer->id);
            $user = User::query()->where('email', mb_strtolower(trim($data['email'])))->lockForUpdate()->first();
            Commerce::ensure($user?->active && $user->hasRole('producer'), 'email', 'Crea primero una cuenta activa con rol Productor para esta persona.');
            Commerce::ensure($producer->user_id === null && ! $user->producer()->exists(), 'email', 'La ficha o la cuenta ya están vinculadas. No se reemplaza una vinculación existente.');
            $producer->user_id = $user->id;
            $producer->save();
            AuditLog::record($actor, 'producer.account_linked', $producer, ['user_id' => $user->id], $data['reason']);
            ProducerPortal::notify($user, 'El equipo vinculó tu ficha de productor. Ya puedes revisar tus ofertas, ventas y recojos anteriores.', route('producer.home'), 'linked:'.$producer->id);
        });

        return back()->with('status', 'Cuenta vinculada a la ficha existente; se conserva todo su historial.');
    }
}
