<?php

namespace App\Http\Controllers;

use App\Enums\DataImportStatus;
use App\Enums\DataImportType;
use App\Enums\Permission;
use App\Http\Requests\DataImportStoreRequest;
use App\Jobs\ProcessDataImportJob;
use App\Models\DataImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataImportController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Permission::MANAGE_DATA_IMPORTS->value);

        $imports = DataImport::query()
            ->with('user:id,name,email')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/imports/index', [
            'imports' => $imports,
            'types' => collect(DataImportType::cases())->map(fn (DataImportType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize(Permission::MANAGE_DATA_IMPORTS->value);

        return Inertia::render('admin/imports/create', [
            'types' => collect(DataImportType::cases())->map(fn (DataImportType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ])->all(),
            // Valid vehicle type codes for the inline hint shown when the
            // user picks the "Vehículos" import (the catalog is dynamic).
            'vehicleTypeCodes' => \App\Models\VehicleType::query()
                ->activeOrdered()
                ->pluck('code')
                ->all(),
        ]);
    }

    public function store(DataImportStoreRequest $request): RedirectResponse
    {
        $userId = $request->user()->id;
        $dryRun = $request->boolean('dry_run');
        $updateExisting = $request->boolean('update_existing');

        if ($request->filled('from_import_id')) {
            // Retry-as-real flow: clone metadata from a completed dry-run import
            $source = DataImport::query()->findOrFail((int) $request->validated('from_import_id'));
            abort_unless($source->hasFiles(), 410, 'El archivo del import original ya no está disponible.');

            $import = DataImport::query()->create([
                'user_id' => $userId,
                'type' => $source->type,
                'original_filename' => $source->original_filename,
                'disk' => $source->disk,
                'path' => $source->path,
                'status' => DataImportStatus::Queued,
                'dry_run' => $dryRun,
                'update_existing' => $updateExisting,
            ]);

            ProcessDataImportJob::dispatch($import);

            return redirect()
                ->route('admin.imports.show', $import)
                ->with('success', 'Carga reencolada para procesamiento real.');
        }

        $file = $request->file('csv');
        $type = $request->validated('type');
        $extension = strtolower($file->getClientOriginalExtension());
        $ulid = (string) Str::ulid();

        $path = $file->storeAs(
            "imports/{$type}",
            "{$ulid}.{$extension}",
            's3'
        );

        $import = DataImport::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 's3',
            'path' => $path,
            'status' => DataImportStatus::Queued,
            'dry_run' => $dryRun,
            'update_existing' => $updateExisting,
        ]);

        ProcessDataImportJob::dispatch($import);

        return redirect()
            ->route('admin.imports.show', $import)
            ->with('success', 'El archivo se subió y entró en cola de procesamiento.');
    }

    public function show(DataImport $import): Response
    {
        Gate::authorize(Permission::MANAGE_DATA_IMPORTS->value);

        $import->load('user:id,name,email');

        return Inertia::render('admin/imports/show', [
            'import' => $import,
        ]);
    }

    public function purge(DataImport $import): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_DATA_IMPORTS->value);

        if ($import->path !== null) {
            Storage::disk($import->disk)->delete($import->path);
        }
        if ($import->errors_path !== null) {
            Storage::disk($import->disk)->delete($import->errors_path);
        }

        $import->update([
            'path' => null,
            'errors_path' => null,
            'files_purged_at' => now(),
        ]);

        return back()->with('success', 'Archivos eliminados.');
    }

    public function downloadSource(DataImport $import): StreamedResponse
    {
        Gate::authorize(Permission::MANAGE_DATA_IMPORTS->value);
        abort_unless($import->hasFiles(), 410, 'Archivo no disponible (purgado).');

        return Storage::disk($import->disk)->download(
            $import->path,
            $import->original_filename,
        );
    }

    public function downloadErrors(DataImport $import): StreamedResponse
    {
        Gate::authorize(Permission::MANAGE_DATA_IMPORTS->value);
        abort_unless($import->errors_path !== null, 404, 'Sin errores para descargar.');
        abort_unless($import->hasFiles(), 410, 'Archivo no disponible (purgado).');

        return Storage::disk($import->disk)->download(
            $import->errors_path,
            "errores_{$import->id}.csv",
        );
    }
}
