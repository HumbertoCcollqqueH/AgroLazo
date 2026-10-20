<?php

namespace App\Http\Controllers;

use App\Events\ConversationUpdated;
use App\Models\CoordinationMessage;
use App\Models\CoordinationThread;
use App\Models\User;
use App\Services\Commerce;
use App\Services\ConversationContext;
use App\Services\ProducerPortal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CoordinationController extends Controller
{
    private function operator(User $user): bool
    {
        return $user->hasPermission('view-operations');
    }

    private function threads(Request $request): Builder
    {
        $user = $request->user();
        $operator = $this->operator($user);
        $filters = $request->validate(['q' => 'nullable|string|max:100']);

        return CoordinationThread::query()->with('user.roles')->withCount('messages')
            ->when(! $operator, fn ($q) => $q->where('user_id', $user->id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('subject', 'ilike', '%'.addcslashes($term, '%_\\').'%')->orWhereHas('user', fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%'))))
            ->with(['messages' => fn ($q) => $q->latest('id')->limit(1)])->latest('updated_at')->latest('id');
    }

    public function index(Request $request): View
    {
        $operator = $this->operator($request->user());
        $threads = $this->threads($request)->paginate(30)->withQueryString();
        $recipients = $operator ? User::query()->where('active', true)->whereHas('roles', fn ($q) => $q->whereIn('code', ['producer', 'buyer']))->with('roles')->orderBy('name')->get(['id', 'name', 'email']) : collect();

        return view('coordination.index', compact('threads', 'operator', 'recipients'));
    }

    public function conversations(Request $request): JsonResponse
    {
        $threads = $this->threads($request)->paginate(30);
        $operator = $this->operator($request->user());

        return response()->json(['threads' => $threads->map(function ($thread) use ($operator, $request) {
            $last = $thread->messages->first();

            return ['id' => $thread->id, 'name' => $operator ? $thread->user->name : 'Equipo AgroLazo', 'subject' => $thread->subject, 'url' => route('coordination.show', $thread), 'preview' => mb_substr($last?->body ?? '', 0, 180), 'time' => $last?->created_at->timezone('America/Lima')->format('d/m H:i'), 'unread' => $thread->unreadFor($request->user(), $operator)];
        }), 'next_page_url' => $threads->nextPageUrl()]);
    }

    public function notifications(Request $request): View
    {
        $request->validate(['unread' => 'nullable|boolean']);
        $user = $request->user();
        $notifications = $user->notifications()->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))->latest()->latest('id')->paginate(20, ['*'], 'alerts')->withQueryString();
        $unread = $user->unreadNotifications()->count();

        return view('coordination.notifications', compact('notifications', 'unread'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['subject' => 'required|string|min:3|max:160', 'body' => 'required|string|min:1|max:4000', 'submission_key' => 'required|uuid', 'recipient_email' => 'nullable|email|max:254', 'recipient_id' => 'nullable|integer']);
        $thread = DB::transaction(function () use ($request, $data): CoordinationThread {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->active, 403);
            $recipient = $user;
            if (! empty($data['recipient_email']) || ! empty($data['recipient_id'])) {
                abort_unless($this->operator($user), 403);
                $recipient = User::query()->when(! empty($data['recipient_id']), fn ($q) => $q->whereKey($data['recipient_id']), fn ($q) => $q->where('email', mb_strtolower(trim($data['recipient_email']))))->where('active', true)
                    ->whereHas('roles', fn ($q) => $q->whereIn('code', ['buyer', 'producer']))->first();
                Commerce::ensure($recipient !== null, 'recipient_email', 'Indica el correo de una cuenta activa de comprador o productor.');
            }
            $existing = CoordinationMessage::query()->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->body === $data['body'] && $existing->thread->user_id === $recipient->id, 409);

                return CoordinationThread::query()->findOrFail($existing->coordination_thread_id);
            }
            $thread = CoordinationThread::query()->create(['user_id' => $recipient->id, 'subject' => $data['subject']]);
            $message = $thread->messages()->create([...$data, 'user_id' => $user->id]);
            ProducerPortal::team('Nueva conversación de '.$user->name.': '.$thread->subject, route('coordination.show', $thread), 'thread-message:'.$message->id);
            if ($recipient->id !== $user->id) {
                ProducerPortal::notify($recipient, 'El equipo inició una conversación contigo: '.$thread->subject, route('coordination.show', $thread), 'thread-message:'.$message->id);
            }
            $this->broadcastMessage($thread, $message);

            return $thread;
        });

        return to_route('coordination.show', $thread)->with('status', 'Conversación creada. La respuesta aparecerá aquí.');
    }

    public function show(Request $request, CoordinationThread $thread): View
    {
        $operator = $this->operator($request->user());
        abort_unless($operator || $thread->user_id === $request->user()->id, 404);
        $messages = $thread->messages()->with('user')->latest('id')->limit(40)->get()->reverse()->values();
        $participantContext = app(ConversationContext::class)->forParticipant($request->user(), $thread->user);

        return view('coordination.show', compact('thread', 'messages', 'operator', 'participantContext'));
    }

    public function reply(Request $request, CoordinationThread $thread): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|min:1|max:4000', 'submission_key' => 'required|uuid']);
        $message = DB::transaction(function () use ($request, $thread, $data): CoordinationMessage {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $thread = CoordinationThread::query()->lockForUpdate()->findOrFail($thread->id);
            $operator = $this->operator($user);
            abort_unless($user->active && ($operator || $thread->user_id === $user->id), 404);
            $existing = CoordinationMessage::query()->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->coordination_thread_id === $thread->id && $existing->body === $data['body'], 409);

                return $existing;
            }
            $message = $thread->messages()->create([...$data, 'user_id' => $user->id]);
            $thread->update(['status' => 'open']);
            $thread->touch();
            $body = 'Nueva respuesta en «'.$thread->subject.'».';
            if ($operator && $thread->user_id !== $user->id) {
                ProducerPortal::notify($thread->user, $body, route('coordination.show', $thread), 'reply:'.$message->id);
            } else {
                ProducerPortal::team($body, route('coordination.show', $thread), 'reply:'.$message->id);
            }
            $this->broadcastMessage($thread, $message);

            return $message;
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => $this->messageData($message->load('user'), $request->user())]);
        }

        return back()->with('status', 'Respuesta enviada.');
    }

    private function broadcastMessage(CoordinationThread $thread, CoordinationMessage $message): void
    {
        $users = User::query()->where('active', true)->whereHas('roles.permissions', fn ($q) => $q->where('code', 'view-operations'))->pluck('id')->push($thread->user_id)->unique()->values()->all();
        ConversationUpdated::dispatch($thread->id, $message->id, $users);
    }

    private function messageData(CoordinationMessage $message, User $user): array
    {
        return ['id' => $message->id, 'name' => $message->user->name, 'body' => $message->body, 'own' => $message->user_id === $user->id, 'created_at' => $message->created_at->toIso8601String(), 'time' => $message->created_at->timezone('America/Lima')->format('d/m H:i'), 'submission_key' => $message->submission_key];
    }

    public function messages(Request $request, CoordinationThread $thread): JsonResponse
    {
        abort_unless($this->operator($request->user()) || $thread->user_id === $request->user()->id, 404);
        $data = $request->validate(['after' => 'nullable|integer|min:0', 'before' => 'nullable|integer|min:1']);
        $older = isset($data['before']);
        $query = $thread->messages()->with('user')->when($older, fn ($q) => $q->where('id', '<', $data['before']), fn ($q) => $q->where('id', '>', $data['after'] ?? 0));
        $messages = ($older ? $query->orderByDesc('id') : $query->orderBy('id'))->limit(61)->get();
        $more = $messages->count() > 60;
        $messages = $messages->take(60);
        if ($older) {
            $messages = $messages->reverse();
        }

        return response()->json(['messages' => $messages->map(fn ($message) => $this->messageData($message, $request->user()))->values(), 'has_more' => $more, 'seen_at' => ($this->operator($request->user()) ? $thread->customer_seen_at : $thread->team_seen_at)?->toIso8601String()]);
    }

    public function seen(Request $request, CoordinationThread $thread): JsonResponse
    {
        $data = $request->validate(['message_id' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $thread, $data): void {
            $thread = CoordinationThread::query()->lockForUpdate()->findOrFail($thread->id);
            $operator = $this->operator($request->user());
            abort_unless($operator || $thread->user_id === $request->user()->id, 404);
            $message = $thread->messages()->findOrFail($data['message_id']);
            $field = $operator ? 'team_seen_at' : 'customer_seen_at';
            $cursor = $operator ? 'team_seen_message_id' : 'customer_seen_message_id';
            if ($message->id > ($thread->$cursor ?? 0)) {
                $thread->update([$field => $message->created_at, $cursor => $message->id]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $notice = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $notice->markAsRead();

        return back();
    }

    public function openNotice(Request $request, string $notification): RedirectResponse
    {
        $notice = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $notice->markAsRead();
        $path = parse_url($notice->data['url'] ?? '', PHP_URL_PATH);
        $query = parse_url($notice->data['url'] ?? '', PHP_URL_QUERY);
        $safe = is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\');

        return redirect($safe ? $path.(is_string($query) && $query !== '' ? '?'.$query : '') : route('coordination.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return to_route('notifications.index')->with('status', 'Tus avisos quedaron marcados como leídos.');
    }

    public function updates(Request $request): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return to_route('notifications.index');
        }
        $user = $request->user();
        $latest = $user->notifications()->latest('created_at')->latest('id')->first(['id']);
        $messages = CoordinationMessage::query()->whereHas('thread', fn ($q) => $q->when(! $this->operator($user), fn ($q) => $q->where('user_id', $user->id)));
        $notices = $user->unreadNotifications()->latest()->latest('id')->limit(20)->get()->map(fn ($notice) => [
            'id' => $notice->id, 'body' => mb_substr($notice->data['body'] ?? 'Tienes una novedad en AgroLazo.', 0, 500),
            'created_at' => $notice->created_at->toIso8601String(),
        ]);

        return response()->json(['account' => $user->id, 'revision' => $user->notifications()->count(), 'unread' => $user->unreadNotifications()->count(), 'notice' => $latest?->id,
            'notices' => $notices, 'message' => $messages->max('id')]);
    }
}
