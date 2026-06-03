<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\FuecNumberRangeStoreRequest;
use App\Http\Requests\FuecNumberRangeUpdateRequest;
use App\Models\FuecNumberRange;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class FuecNumberRangeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::MANAGE_FUEC_NUMBER_RANGES->value);

        $ranges = QueryBuilder::for(FuecNumberRange::class)
            ->allowedFilters(...[
                AllowedFilter::exact('active'),
                'resolution_number',
            ])
            ->allowedSorts(...['resolution_year', 'range_from', 'range_to', 'active', 'created_at'])
            ->defaultSort('-active', '-resolution_year', '-created_at')
            ->paginate($request->perPage())
            ->withQueryString()
            ->through(fn (FuecNumberRange $range) => array_merge($range->toArray(), [
                'remaining' => $range->remaining(),
            ]));

        return Inertia::render('fuec-number-ranges/index', [
            'ranges' => $ranges,
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize(Permission::MANAGE_FUEC_NUMBER_RANGES->value);

        return Inertia::render('fuec-number-ranges/create');
    }

    public function store(FuecNumberRangeStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_FUEC_NUMBER_RANGES->value);

        $data = $request->validated();

        try {
            DB::transaction(function () use ($data): void {
                $this->deactivateOthersIfActivating($data);
                FuecNumberRange::create($data);
            });
        } catch (QueryException $e) {
            $this->rethrowAsValidationErrorOnActiveConflict($e);
        }

        return redirect()->route('fuec-number-ranges.index');
    }

    public function show(Request $request, FuecNumberRange $fuecNumberRange): Response
    {
        Gate::authorize(Permission::MANAGE_FUEC_NUMBER_RANGES->value);

        return Inertia::render('fuec-number-ranges/show', [
            'range' => array_merge($fuecNumberRange->toArray(), [
                'remaining' => $fuecNumberRange->remaining(),
                'used' => $fuecNumberRange->fuecs()->count(),
            ]),
        ]);
    }

    public function edit(Request $request, FuecNumberRange $fuecNumberRange): Response
    {
        Gate::authorize(Permission::MANAGE_FUEC_NUMBER_RANGES->value);

        return Inertia::render('fuec-number-ranges/edit', [
            'range' => $fuecNumberRange,
        ]);
    }

    public function update(FuecNumberRangeUpdateRequest $request, FuecNumberRange $fuecNumberRange): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_FUEC_NUMBER_RANGES->value);

        $data = $request->validated();

        try {
            DB::transaction(function () use ($data, $fuecNumberRange): void {
                $this->deactivateOthersIfActivating($data, $fuecNumberRange->id);
                $fuecNumberRange->update($data);
            });
        } catch (QueryException $e) {
            $this->rethrowAsValidationErrorOnActiveConflict($e);
        }

        return redirect()->route('fuec-number-ranges.index');
    }

    public function destroy(Request $request, FuecNumberRange $fuecNumberRange): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_FUEC_NUMBER_RANGES->value);

        if ($fuecNumberRange->fuecs()->exists()) {
            throw ValidationException::withMessages([
                'fuecs' => 'No se puede eliminar un rango que tiene FUECs asociados.',
            ]);
        }

        $fuecNumberRange->delete();

        return redirect()->route('fuec-number-ranges.index');
    }

    /**
     * Enforce the "at most one active range" invariant application-side
     * (the partial unique index only lives on Postgres). When the admin
     * activates a new range, deactivate all others first.
     *
     * @param  array<string, mixed>  $data
     */
    protected function deactivateOthersIfActivating(array $data, ?int $exceptId = null): void
    {
        if (empty($data['active'])) {
            return;
        }

        $query = FuecNumberRange::query()->where('active', true);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $query->update(['active' => false]);
    }

    protected function rethrowAsValidationErrorOnActiveConflict(QueryException $e): void
    {
        $message = $e->getMessage();
        if (str_contains($message, 'fuec_number_ranges_one_active_uidx')
            || str_contains($message, 'one_active')
        ) {
            throw ValidationException::withMessages([
                'active' => 'Ya existe un rango activo. Desactive el rango vigente antes de activar uno nuevo.',
            ]);
        }

        throw $e;
    }
}
