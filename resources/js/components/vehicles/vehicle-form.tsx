import { CircleCheck, PowerOff, Wrench } from 'lucide-react';
import FieldFooter from '@/components/field-footer';
import {
    Choicebox,
    ChoiceboxIndicator,
    ChoiceboxItem,
    ChoiceboxItemHeader,
    ChoiceboxItemTitle,
} from '@/components/kibo-ui/choicebox';
import MunicipalityCombobox, {
    type MunicipalityOption,
} from '@/components/municipality-combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PhoneInput from '@/components/ui/phone-input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { VehicleType, VehicleTypeLabel } from '@/enums/VehicleType';

const VEHICLE_STATUS_OPTIONS: Array<{
    value: string;
    label: string;
    icon: typeof CircleCheck;
    iconClass: string;
}> = [
    {
        value: 'active',
        label: 'Activo',
        icon: CircleCheck,
        iconClass: 'text-emerald-600 dark:text-emerald-400',
    },
    {
        value: 'maintenance',
        label: 'En Mantenimiento',
        icon: Wrench,
        iconClass: 'text-amber-600 dark:text-amber-400',
    },
    {
        value: 'retired',
        label: 'Retirado',
        icon: PowerOff,
        iconClass: 'text-muted-foreground',
    },
];

export interface ThirdPartyOption {
    id: number;
    identification_number: string;
    first_name: string | null;
    first_lastname: string | null;
    company_name: string | null;
    is_natural_person: boolean;
}

export interface VehicleFormData {
    internal_code: string;
    plate: string;
    mobile_number: string;
    brand: string;
    line: string;
    model_year: string;
    type: string;
    engine_number: string;
    chassis_number: string;
    capacity: string;
    municipality_id: string;
    is_third_party: boolean;
    third_party_id: string;
    soat_due_date: string;
    rtm_due_date: string;
    operation_card_due_date: string;
    status: string;
}

function thirdPartyLabel(tp: ThirdPartyOption): string {
    if (tp.is_natural_person) {
        return `${tp.first_name} ${tp.first_lastname} (${tp.identification_number})`;
    }
    return `${tp.company_name} (${tp.identification_number})`;
}

function RequiredMarker() {
    return <span className="text-destructive"> *</span>;
}

interface VehicleFormProps {
    data: VehicleFormData;
    setData: <K extends keyof VehicleFormData>(
        key: K,
        value: VehicleFormData[K],
    ) => void;
    errors: Partial<Record<keyof VehicleFormData, string>>;
    municipalities: MunicipalityOption[];
    thirdParties: ThirdPartyOption[];
    idPrefix?: string;
}

export default function VehicleForm({
    data,
    setData,
    errors,
    municipalities,
    thirdParties,
    idPrefix = '',
}: VehicleFormProps) {
    const id = (name: string) => (idPrefix ? `${idPrefix}_${name}` : name);
    const invalid = (field: keyof VehicleFormData) =>
        errors[field] ? true : undefined;

    return (
        <>
            <div className="grid gap-4 md:grid-cols-3 md:grid-rows-[auto_1fr_auto]">
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('internal_code')}
                >
                    <Label htmlFor={id('internal_code')}>Código Interno</Label>
                    <Input
                        id={id('internal_code')}
                        value={data.internal_code}
                        aria-invalid={invalid('internal_code')}
                        onChange={(e) =>
                            setData('internal_code', e.target.value)
                        }
                        placeholder="V-001"
                    />
                    <FieldFooter error={errors.internal_code} />
                </div>
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('plate')}
                >
                    <Label htmlFor={id('plate')}>
                        Placa
                        <RequiredMarker />
                    </Label>
                    <Input
                        id={id('plate')}
                        value={data.plate}
                        aria-invalid={invalid('plate')}
                        onChange={(e) =>
                            setData('plate', e.target.value.toUpperCase())
                        }
                        maxLength={6}
                        placeholder="ABC123"
                        className="font-mono tracking-wider uppercase"
                        autoCapitalize="characters"
                    />
                    <FieldFooter error={errors.plate} />
                </div>
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('mobile_number')}
                >
                    <Label htmlFor={id('mobile_number')}>Número Móvil</Label>
                    <PhoneInput
                        id={id('mobile_number')}
                        value={data.mobile_number}
                        onValueChange={(raw) => setData('mobile_number', raw)}
                        invalid={invalid('mobile_number')}
                    />
                    <FieldFooter error={errors.mobile_number} />
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-3 md:grid-rows-[auto_1fr_auto]">
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('brand')}
                >
                    <Label htmlFor={id('brand')}>Marca</Label>
                    <Input
                        id={id('brand')}
                        value={data.brand}
                        aria-invalid={invalid('brand')}
                        onChange={(e) => setData('brand', e.target.value)}
                    />
                    <FieldFooter error={errors.brand} />
                </div>
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('line')}
                >
                    <Label htmlFor={id('line')}>Línea</Label>
                    <Input
                        id={id('line')}
                        value={data.line}
                        aria-invalid={invalid('line')}
                        onChange={(e) => setData('line', e.target.value)}
                    />
                    <FieldFooter error={errors.line} />
                </div>
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('model_year')}
                >
                    <Label htmlFor={id('model_year')}>Año Modelo</Label>
                    <Input
                        id={id('model_year')}
                        type="number"
                        value={data.model_year}
                        aria-invalid={invalid('model_year')}
                        onChange={(e) => setData('model_year', e.target.value)}
                    />
                    <FieldFooter error={errors.model_year} />
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-3 md:grid-rows-[auto_1fr_auto]">
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('type')}
                >
                    <Label htmlFor={id('type')}>
                        Tipo
                        <RequiredMarker />
                    </Label>
                    <Select
                        value={data.type}
                        onValueChange={(value) => setData('type', value)}
                    >
                        <SelectTrigger
                            id={id('type')}
                            aria-invalid={invalid('type')}
                        >
                            <SelectValue placeholder="Seleccionar..." />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.values(VehicleType).map((value) => (
                                <SelectItem key={value} value={value}>
                                    {VehicleTypeLabel[value]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FieldFooter error={errors.type} />
                </div>
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('capacity')}
                >
                    <Label htmlFor={id('capacity')}>
                        Capacidad
                        <RequiredMarker />
                    </Label>
                    <Input
                        id={id('capacity')}
                        type="number"
                        value={data.capacity}
                        aria-invalid={invalid('capacity')}
                        onChange={(e) => setData('capacity', e.target.value)}
                    />
                    <FieldFooter error={errors.capacity} />
                </div>
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('municipality_id')}
                >
                    <Label htmlFor={id('municipality_id')}>Ciudad</Label>
                    <MunicipalityCombobox
                        id={id('municipality_id')}
                        municipalities={municipalities}
                        value={data.municipality_id}
                        onChange={(val) => setData('municipality_id', val)}
                        invalid={!!errors.municipality_id}
                    />
                    <FieldFooter error={errors.municipality_id} />
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2 md:grid-rows-[auto_1fr_auto]">
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('engine_number')}
                >
                    <Label htmlFor={id('engine_number')}>Número de Motor</Label>
                    <Input
                        id={id('engine_number')}
                        value={data.engine_number}
                        aria-invalid={invalid('engine_number')}
                        onChange={(e) =>
                            setData('engine_number', e.target.value)
                        }
                    />
                    <FieldFooter error={errors.engine_number} />
                </div>
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('chassis_number')}
                >
                    <Label htmlFor={id('chassis_number')}>
                        Número de Chasis
                    </Label>
                    <Input
                        id={id('chassis_number')}
                        value={data.chassis_number}
                        aria-invalid={invalid('chassis_number')}
                        onChange={(e) =>
                            setData('chassis_number', e.target.value)
                        }
                    />
                    <FieldFooter error={errors.chassis_number} />
                </div>
            </div>

            <div className="flex items-center gap-3">
                <Switch
                    id={id('is_third_party')}
                    checked={data.is_third_party}
                    onCheckedChange={(checked) =>
                        setData('is_third_party', checked === true)
                    }
                />
                <Label htmlFor={id('is_third_party')}>
                    Vehículo de Tercero
                </Label>
                <FieldFooter error={errors.is_third_party} />
            </div>

            {data.is_third_party && (
                <div
                    className="group/field grid gap-2"
                    data-error={invalid('third_party_id')}
                >
                    <Label htmlFor={id('third_party_id')}>
                        Tercero Propietario
                        <RequiredMarker />
                    </Label>
                    <Select
                        value={data.third_party_id}
                        onValueChange={(value) =>
                            setData('third_party_id', value)
                        }
                    >
                        <SelectTrigger
                            id={id('third_party_id')}
                            aria-invalid={invalid('third_party_id')}
                        >
                            <SelectValue placeholder="Seleccionar tercero..." />
                        </SelectTrigger>
                        <SelectContent>
                            {thirdParties.map((tp) => (
                                <SelectItem key={tp.id} value={String(tp.id)}>
                                    {thirdPartyLabel(tp)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FieldFooter error={errors.third_party_id} />
                </div>
            )}

            <div className="grid gap-4 md:grid-cols-3 md:grid-rows-[auto_1fr_auto]">
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('soat_due_date')}
                >
                    <Label htmlFor={id('soat_due_date')}>
                        Vencimiento SOAT
                        <RequiredMarker />
                    </Label>
                    <Input
                        id={id('soat_due_date')}
                        type="date"
                        value={data.soat_due_date}
                        aria-invalid={invalid('soat_due_date')}
                        onChange={(e) =>
                            setData('soat_due_date', e.target.value)
                        }
                    />
                    <FieldFooter error={errors.soat_due_date} />
                </div>
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('rtm_due_date')}
                >
                    <Label htmlFor={id('rtm_due_date')}>
                        Vencimiento RTM
                        <RequiredMarker />
                    </Label>
                    <Input
                        id={id('rtm_due_date')}
                        type="date"
                        value={data.rtm_due_date}
                        aria-invalid={invalid('rtm_due_date')}
                        onChange={(e) =>
                            setData('rtm_due_date', e.target.value)
                        }
                    />
                    <FieldFooter error={errors.rtm_due_date} />
                </div>
                <div
                    className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                    data-error={invalid('operation_card_due_date')}
                >
                    <Label htmlFor={id('operation_card_due_date')}>
                        Vencimiento Tarjeta de Operación
                        <RequiredMarker />
                    </Label>
                    <Input
                        id={id('operation_card_due_date')}
                        type="date"
                        value={data.operation_card_due_date}
                        aria-invalid={invalid('operation_card_due_date')}
                        onChange={(e) =>
                            setData('operation_card_due_date', e.target.value)
                        }
                    />
                    <FieldFooter error={errors.operation_card_due_date} />
                </div>
            </div>

            <div
                className="group/field grid gap-2"
                data-error={invalid('status')}
            >
                <Label htmlFor={id('status')}>Estado</Label>
                <Choicebox
                    id={id('status')}
                    value={data.status}
                    onValueChange={(value) => {
                        if (!value) return;
                        setData('status', value);
                    }}
                    aria-invalid={invalid('status')}
                    className="grid grid-cols-1 gap-2 sm:grid-cols-3"
                >
                    {VEHICLE_STATUS_OPTIONS.map((opt) => {
                        const Icon = opt.icon;
                        return (
                            <ChoiceboxItem
                                key={opt.value}
                                id={`${id('status')}-${opt.value}`}
                                value={opt.value}
                                className="h-full items-center!"
                            >
                                <ChoiceboxItemHeader>
                                    <ChoiceboxItemTitle className="flex items-center gap-2">
                                        <Icon
                                            aria-hidden
                                            className={`size-4 shrink-0 ${opt.iconClass}`}
                                        />
                                        <span>{opt.label}</span>
                                    </ChoiceboxItemTitle>
                                </ChoiceboxItemHeader>
                                <ChoiceboxIndicator />
                            </ChoiceboxItem>
                        );
                    })}
                </Choicebox>
                <FieldFooter error={errors.status} />
            </div>
        </>
    );
}
