<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NormalizeQuantityRequest;
use App\Http\Requests\Admin\SaveCatalogRequest;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\CatalogRegistry;
use App\Services\UnitConversionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CatalogController extends Controller
{
    public function __construct(private CatalogRegistry $registry) {}

    public function index(Request $request, string $catalog): View
    {
        Gate::authorize('view-catalogs');
        $definition = $this->registry->definition($catalog);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive']]);
        $query = $definition['model']::query()->with($definition['relations']);
        $search = $filters['q'] ?? '';
        if ($search !== '') {
            $column = $catalog === 'conversions' ? 'context' : 'name';
            $query->where($column, 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }
        if ($request->filled('status')) {
            $query->where('active', $filters['status'] === 'active');
        }

        return view('catalog.index', [
            'catalog' => $catalog, 'definition' => $definition, 'registry' => $this->registry,
            'records' => $query->orderBy($catalog === 'conversions' ? 'id' : 'name')->paginate(15)->withQueryString(),
            'normalizationProducts' => $catalog === 'conversions' ? $this->registry->options('products') : [],
            'normalizationUnits' => $catalog === 'conversions' ? $this->registry->options('allUnits') : [],
        ]);
    }

    public function create(string $catalog): View
    {
        Gate::authorize('manage-catalogs');

        return $this->form($catalog, null);
    }

    public function edit(string $catalog, int $id): View
    {
        Gate::authorize('manage-catalogs');

        return $this->form($catalog, $this->registry->record($catalog, $id));
    }

    private function form(string $catalog, ?Model $record): View
    {
        $definition = $this->registry->definition($catalog);
        $options = [];
        foreach ($definition['fields'] as $key => $field) {
            if (isset($field['options'])) {
                $options[$key] = $this->registry->options($field['options'], $record?->getAttribute($key));
            }
        }

        return view('catalog.form', compact('catalog', 'definition', 'record', 'options'));
    }

    public function store(SaveCatalogRequest $request, string $catalog): RedirectResponse
    {
        return $this->save($request, $catalog, null);
    }

    public function update(SaveCatalogRequest $request, string $catalog, int $id): RedirectResponse
    {
        return $this->save($request, $catalog, $id);
    }

    private function save(SaveCatalogRequest $request, string $catalog, ?int $id): RedirectResponse
    {
        $definition = $this->registry->definition($catalog);
        DB::transaction(function () use ($request, $catalog, $id, $definition): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('manage-catalogs');
            Validator::make($request->all(), $request->rules(), $request->messages())->validate();
            $record = $id ? $definition['model']::query()->lockForUpdate()->findOrFail($id) : new $definition['model'];
            $before = $record->exists ? $record->attributesToArray() : null;
            $data = $request->safe()->except('reason');
            if ($catalog === 'conversions' && ! $record->exists) {
                $data['verified_by'] = $actor->id;
                $data['verified_at'] = now();
            }
            $record->fill($data);
            $request->validateReferences($record);
            $record->save();
            AuditLog::record($actor, $id ? 'catalog.updated' : 'catalog.created', $record,
                ['catalog' => $catalog, 'before' => $before, 'after' => $record->fresh()->attributesToArray()],
                $request->validated('reason'));
        });

        return to_route('catalog.index', $catalog)->with('status', 'Registro guardado. El cambio quedó en auditoría.');
    }

    public function normalize(NormalizeQuantityRequest $request, UnitConversionService $service): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $product = Product::query()->findOrFail($data['product_id']);
        $result = $service->convert($data['quantity'], (int) $data['from_unit_id'], $product->base_unit_id, $product->id, $data['context'] ?? '', $data['date'] ?? null);
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return to_route('catalog.index', 'conversions')->with('normalization', [
            ...$result, 'unit' => $product->baseUnit->code, 'product' => $product->name,
            'input' => $data['quantity'].' '.Unit::query()->findOrFail($data['from_unit_id'])->code,
        ])->withInput($data);
    }
}
