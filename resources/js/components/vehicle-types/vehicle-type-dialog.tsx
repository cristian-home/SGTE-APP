import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import VehicleTypeController from '@/actions/App/Http/Controllers/VehicleTypeController';
import FieldFooter from '@/components/field-footer';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type { LicenseCategoryOption } from '@/pages/vehicle-types/index';
import type { VehicleType } from '@/types';

interface VehicleTypeDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mode: 'create' | 'edit';
    vehicleType?: VehicleType | null;
    licenseCategories: LicenseCategoryOption[];
}

const emptyData = {
    code: '',
    name: '',
    allowed_license_categories: [] as string[],
    active: true,
    sort_order: 0,
};

export default function VehicleTypeDialog({
    open,
    onOpenChange,
    mode,
    vehicleType,
    licenseCategories,
}: VehicleTypeDialogProps) {
    const { data, setData, post, put, processing, errors, clearErrors } =
        useForm({ ...emptyData });

    useEffect(() => {
        if (!open) {
            return;
        }
        if (mode === 'edit' && vehicleType) {
            setData({
                code: vehicleType.code,
                name: vehicleType.name,
                allowed_license_categories:
                    vehicleType.allowed_license_categories ?? [],
                active: vehicleType.active,
                sort_order: vehicleType.sort_order,
            });
        } else {
            setData({ ...emptyData });
        }
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, mode, vehicleType?.id]);

    function toggleCategory(value: string): void {
        setData(
            'allowed_license_categories',
            data.allowed_license_categories.includes(value)
                ? data.allowed_license_categories.filter((c) => c !== value)
                : [...data.allowed_license_categories, value],
        );
    }

    function submit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        // This dialog owns its <form>; stop the submit event from bubbling
        // through the React tree to an ancestor <form>. See BUG-002.
        e.stopPropagation();
        if (mode === 'create') {
            post(VehicleTypeController.store().url, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });
        } else if (vehicleType) {
            put(VehicleTypeController.update(vehicleType.id).url, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {mode === 'create'
                                ? 'Crear Tipo de Vehículo'
                                : 'Editar Tipo de Vehículo'}
                        </DialogTitle>
                        <DialogDescription>
                            El código identifica el tipo en las importaciones y
                            no debería cambiar una vez en uso.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="vehicle-type-code">
                                Código
                                <span className="text-destructive">{' *'}</span>
                            </Label>
                            <Input
                                id="vehicle-type-code"
                                value={data.code}
                                maxLength={30}
                                aria-invalid={!!errors.code}
                                onChange={(e) =>
                                    setData(
                                        'code',
                                        e.target.value
                                            .toLowerCase()
                                            .replace(/\s+/g, '_'),
                                    )
                                }
                                className="font-mono"
                                placeholder="microbus"
                            />
                            <FieldFooter error={errors.code} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="vehicle-type-name">
                                Nombre
                                <span className="text-destructive">{' *'}</span>
                            </Label>
                            <Input
                                id="vehicle-type-name"
                                value={data.name}
                                maxLength={60}
                                aria-invalid={!!errors.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                placeholder="Microbús"
                            />
                            <FieldFooter error={errors.name} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label>Categorías de licencia permitidas</Label>
                        <div className="flex flex-wrap gap-2">
                            {licenseCategories.map((cat) => {
                                const selected =
                                    data.allowed_license_categories.includes(
                                        cat.value,
                                    );
                                return (
                                    <Button
                                        key={cat.value}
                                        type="button"
                                        size="sm"
                                        variant={
                                            selected ? 'default' : 'outline'
                                        }
                                        className={cn(
                                            'font-mono',
                                            !selected &&
                                                'text-muted-foreground',
                                        )}
                                        onClick={() =>
                                            toggleCategory(cat.value)
                                        }
                                        aria-pressed={selected}
                                    >
                                        {cat.label}
                                    </Button>
                                );
                            })}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Categorías de conductor que pueden operar este tipo.
                        </p>
                        <FieldFooter
                            error={errors.allowed_license_categories}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="vehicle-type-sort">Orden</Label>
                            <Input
                                id="vehicle-type-sort"
                                type="number"
                                min={0}
                                value={String(data.sort_order)}
                                aria-invalid={!!errors.sort_order}
                                onChange={(e) =>
                                    setData(
                                        'sort_order',
                                        Number(e.target.value) || 0,
                                    )
                                }
                            />
                            <FieldFooter error={errors.sort_order} />
                        </div>
                        <div className="flex items-center gap-3 self-end pb-2">
                            <Switch
                                id="vehicle-type-active"
                                checked={data.active}
                                onCheckedChange={(checked) =>
                                    setData('active', checked)
                                }
                            />
                            <Label htmlFor="vehicle-type-active">Activo</Label>
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {mode === 'create' ? 'Guardar' : 'Actualizar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
