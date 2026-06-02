import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileSpreadsheet, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { DataImportType } from '@/enums/DataImportType';
import type { BreadcrumbItem } from '@/types';

interface TypeOption {
    value: DataImportType;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Administración', href: '#' },
    { title: 'Importaciones', href: '/admin/imports' },
    { title: 'Nueva carga', href: '/admin/imports/create' },
];

const MAX_BYTES = 20 * 1024 * 1024;

export default function ImportsCreate({
    types,
    vehicleTypeCodes,
}: {
    types: TypeOption[];
    vehicleTypeCodes: string[];
}) {
    const [type, setType] = useState<string>('');
    const [file, setFile] = useState<File | null>(null);
    const [clientError, setClientError] = useState<string | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const fileInputRef = useRef<HTMLInputElement | null>(null);

    function acceptFile(f: File | null) {
        if (!f) {
            setFile(null);
            setClientError(null);
            return;
        }
        if (f.size > MAX_BYTES) {
            setFile(null);
            setClientError('El archivo excede el límite de 20 MB.');
            return;
        }
        const ext = f.name.split('.').pop()?.toLowerCase() ?? '';
        if (!['csv', 'txt', 'xlsx'].includes(ext)) {
            setFile(null);
            setClientError('Solo se aceptan archivos CSV o XLSX.');
            return;
        }
        setFile(f);
        setClientError(null);
    }

    function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
        acceptFile(e.target.files?.[0] ?? null);
    }

    function handleDrop(e: React.DragEvent<HTMLLabelElement>) {
        e.preventDefault();
        setIsDragging(false);
        const f = e.dataTransfer.files?.[0] ?? null;
        if (fileInputRef.current && f) {
            // Mirror the dropped file into the hidden input so the Form
            // submit picks it up under the `csv` field name.
            const dt = new DataTransfer();
            dt.items.add(f);
            fileInputRef.current.files = dt.files;
        }
        acceptFile(f);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nueva carga" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Nueva carga</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            action="/admin/imports"
                            method="post"
                            encType="multipart/form-data"
                            options={{ preserveScroll: true }}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="flex flex-col gap-2">
                                        <Label htmlFor="type">
                                            Tipo de carga *
                                        </Label>
                                        <Select
                                            value={type}
                                            onValueChange={setType}
                                            name="type"
                                        >
                                            <SelectTrigger id="type">
                                                <SelectValue placeholder="Selecciona…" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {types.map((t) => (
                                                    <SelectItem
                                                        key={t.value}
                                                        value={t.value}
                                                    >
                                                        {t.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <input
                                            type="hidden"
                                            name="type"
                                            value={type}
                                        />
                                        {errors.type && (
                                            <p className="text-sm text-destructive">
                                                {errors.type}
                                            </p>
                                        )}
                                        {type === 'vehicles' &&
                                            vehicleTypeCodes.length > 0 && (
                                                <p className="text-xs text-muted-foreground">
                                                    Tipos válidos para la
                                                    columna{' '}
                                                    <code>type</code>:{' '}
                                                    {vehicleTypeCodes.join(', ')}
                                                    .
                                                </p>
                                            )}
                                    </div>

                                    <div className="flex flex-col gap-2">
                                        <Label htmlFor="csv">Archivo *</Label>
                                        <label
                                            htmlFor="csv"
                                            onDragOver={(e) => {
                                                e.preventDefault();
                                                setIsDragging(true);
                                            }}
                                            onDragLeave={() =>
                                                setIsDragging(false)
                                            }
                                            onDrop={handleDrop}
                                            className={cn(
                                                'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-input bg-muted/30 p-6 text-center transition-colors hover:bg-muted/60',
                                                isDragging &&
                                                    'border-primary bg-primary/5',
                                            )}
                                        >
                                            {file ? (
                                                <>
                                                    <FileSpreadsheet
                                                        aria-hidden
                                                        className="size-8 text-primary"
                                                    />
                                                    <div className="font-mono text-sm">
                                                        {file.name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {(
                                                            file.size /
                                                            (1024 * 1024)
                                                        ).toFixed(2)}{' '}
                                                        MB · Pulsa o suelta otro
                                                        archivo para reemplazar
                                                    </div>
                                                </>
                                            ) : (
                                                <>
                                                    <Upload
                                                        aria-hidden
                                                        className="size-8 text-muted-foreground"
                                                    />
                                                    <div className="text-sm font-medium">
                                                        Arrastra el archivo aquí
                                                        o haz clic para
                                                        seleccionar
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        CSV o XLSX · máximo 20
                                                        MB
                                                    </div>
                                                </>
                                            )}
                                        </label>
                                        <input
                                            ref={fileInputRef}
                                            id="csv"
                                            name="csv"
                                            type="file"
                                            accept=".csv,.txt,.xlsx"
                                            onChange={handleFileChange}
                                            className="sr-only"
                                        />
                                        {clientError && (
                                            <p className="text-sm text-destructive">
                                                {clientError}
                                            </p>
                                        )}
                                        {errors.csv && (
                                            <p className="text-sm text-destructive">
                                                {errors.csv}
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex items-start gap-2">
                                        <Checkbox
                                            id="dry_run"
                                            name="dry_run"
                                            value="1"
                                        />
                                        <div className="flex flex-col">
                                            <Label
                                                htmlFor="dry_run"
                                                className="cursor-pointer"
                                            >
                                                Solo validar (no escribir
                                                cambios)
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                Recomendado en la primera prueba
                                                para validar el formato sin
                                                tocar la base de datos.
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-start gap-2">
                                        <Checkbox
                                            id="update_existing"
                                            name="update_existing"
                                            value="1"
                                        />
                                        <div className="flex flex-col">
                                            <Label
                                                htmlFor="update_existing"
                                                className="cursor-pointer"
                                            >
                                                Actualizar registros existentes
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                Si está activo, las filas con
                                                clave existente se actualizan.
                                                Si no, se ignoran.
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex justify-end gap-2 pt-2">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            asChild
                                        >
                                            <Link href="/admin/imports">
                                                <ArrowLeft className="mr-1 size-4" />
                                                Cancelar
                                            </Link>
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                !type ||
                                                !file ||
                                                !!clientError
                                            }
                                        >
                                            <Upload className="mr-1 size-4" />
                                            Subir y procesar
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
