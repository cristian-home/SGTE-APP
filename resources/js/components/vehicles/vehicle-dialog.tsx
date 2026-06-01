import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import VehicleController from '@/actions/App/Http/Controllers/VehicleController';
import { type MunicipalityOption } from '@/components/municipality-combobox';
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
import VehicleForm, {
    type ThirdPartyOption,
    type VehicleFormData,
    type VehicleTypeOption,
} from '@/components/vehicles/vehicle-form';
import type { Vehicle } from '@/types/models';

/** Subset of a Vehicle needed to pre-fill the form in edit mode. */
export type EditableVehicle = Pick<
    Vehicle,
    | 'id'
    | 'internal_code'
    | 'plate'
    | 'mobile_number'
    | 'brand'
    | 'line'
    | 'model_year'
    | 'vehicle_type_id'
    | 'engine_number'
    | 'chassis_number'
    | 'capacity'
    | 'municipality_id'
    | 'is_third_party'
    | 'third_party_id'
    | 'soat_due_date'
    | 'rtm_due_date'
    | 'operation_card_due_date'
    | 'status'
>;

interface VehicleDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mode: 'create' | 'edit';
    vehicle?: EditableVehicle | null;
    municipalities: MunicipalityOption[];
    thirdParties: ThirdPartyOption[];
    vehicleTypes: VehicleTypeOption[];
    /**
     * Next sequential internal code (e.g. "V-006") suggested by the
     * backend; pre-filled in the form on create. The user may overwrite
     * it before saving, or clear it to let the model autogenerate again.
     */
    suggestedInternalCode?: string;
}

const emptyData: VehicleFormData = {
    internal_code: '',
    plate: '',
    mobile_number: '',
    brand: '',
    line: '',
    model_year: '',
    vehicle_type_id: '',
    engine_number: '',
    chassis_number: '',
    capacity: '',
    municipality_id: '',
    is_third_party: false,
    third_party_id: '',
    soat_due_date: '',
    rtm_due_date: '',
    operation_card_due_date: '',
    status: 'active',
};

function dataFromVehicle(vehicle: EditableVehicle): VehicleFormData {
    return {
        internal_code: vehicle.internal_code,
        plate: vehicle.plate,
        mobile_number: vehicle.mobile_number ?? '',
        brand: vehicle.brand,
        line: vehicle.line,
        model_year: String(vehicle.model_year),
        vehicle_type_id: String(vehicle.vehicle_type_id),
        engine_number: vehicle.engine_number ?? '',
        chassis_number: vehicle.chassis_number ?? '',
        capacity: String(vehicle.capacity),
        municipality_id: vehicle.municipality_id
            ? String(vehicle.municipality_id)
            : '',
        is_third_party: vehicle.is_third_party,
        third_party_id: vehicle.third_party_id
            ? String(vehicle.third_party_id)
            : '',
        soat_due_date: vehicle.soat_due_date,
        rtm_due_date: vehicle.rtm_due_date,
        operation_card_due_date: vehicle.operation_card_due_date,
        status: vehicle.status,
    };
}

export default function VehicleDialog({
    open,
    onOpenChange,
    mode,
    vehicle,
    municipalities,
    thirdParties,
    vehicleTypes,
    suggestedInternalCode,
}: VehicleDialogProps) {
    const { data, setData, post, put, processing, errors, clearErrors } =
        useForm<VehicleFormData>({ ...emptyData });

    // Re-seed the form whenever the dialog identity changes. Inertia's
    // `setData`/`clearErrors` aren't React state setters, so this effect
    // is not flagged by the React Compiler (same pattern as UserDialog).
    useEffect(() => {
        if (!open) {
            return;
        }
        if (mode === 'edit' && vehicle) {
            setData(dataFromVehicle(vehicle));
        } else {
            setData({
                ...emptyData,
                internal_code: suggestedInternalCode ?? '',
            });
        }
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, mode, vehicle?.id, suggestedInternalCode]);

    function submit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        // This dialog owns its <form>; stop the submit event from bubbling
        // through the React tree to an ancestor <form>. See BUG-002.
        e.stopPropagation();
        if (mode === 'create') {
            post(VehicleController.store().url, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });
        } else if (vehicle) {
            put(VehicleController.update(vehicle.id).url, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[calc(100vh-4rem)] flex-col px-0 sm:max-w-2xl">
                <DialogHeader className="px-6">
                    <DialogTitle>
                        {mode === 'create'
                            ? 'Crear Vehículo'
                            : 'Editar Vehículo'}
                    </DialogTitle>
                    <DialogDescription>
                        {mode === 'create'
                            ? 'Complete los campos para registrar un nuevo vehículo.'
                            : 'Actualice la información del vehículo.'}
                    </DialogDescription>
                </DialogHeader>

                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex-1 space-y-6 overflow-y-auto px-6 py-2">
                        <VehicleForm
                            data={data}
                            setData={setData}
                            errors={errors}
                            municipalities={municipalities}
                            thirdParties={thirdParties}
                            vehicleTypes={vehicleTypes}
                            idPrefix="dlg"
                        />
                    </div>

                    <DialogFooter className="mt-4 gap-2 px-6">
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
