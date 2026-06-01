import { usePage } from '@inertiajs/react';
import { APIProvider } from '@vis.gl/react-google-maps';
import { endOfDay, format as formatDate, startOfDay } from 'date-fns';
import { es } from 'date-fns/locale';
import {
    AlertTriangle,
    ArrowRightLeft,
    Banknote,
    CalendarClock,
    CreditCard,
    Info,
    Lock,
    Plus,
    ShieldAlert,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import FieldFooter from '@/components/field-footer';
import {
    Choicebox,
    ChoiceboxIndicator,
    ChoiceboxItem,
    ChoiceboxItemHeader,
    ChoiceboxItemTitle,
} from '@/components/kibo-ui/choicebox';
import LocationField, {
    type CoordinatesSource,
} from '@/components/location-field';
import MapPickerModal from '@/components/map-picker-modal';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import BillingGroupsInput from '@/components/services/billing-groups-input';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DateTimePicker } from '@/components/ui/datetime-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import MoneyInput from '@/components/ui/money-input';
import SearchableCombobox from '@/components/ui/searchable-combobox';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { PaymentMethod, PaymentMethodLabel } from '@/enums/PaymentMethod';
import { ServiceStatus, ServiceStatusLabel } from '@/enums/ServiceStatus';
import { dateToWallClock, viewerToday, wallClockToDate } from '@/lib/datetime';
import { GOOGLE_MAPS_BROWSER_KEY } from '@/lib/google-maps';
import { normalizeCity } from '@/lib/normalize-city';
import { cn } from '@/lib/utils';
import type { DayStatus } from '@/types/models';

export interface VehicleOption {
    id: number;
    plate: string;
    /** Vehicle type from the vehicle_types catalog (eager-loaded). */
    vehicle_type?: { id: number; code: string; name: string } | null;
    is_third_party: boolean;
    third_party_id: number | null;
    third_party?: ThirdPartyOption | null;
}

export interface ThirdPartyOption {
    id: number;
    identification_number: string;
    first_name: string | null;
    first_lastname: string | null;
    company_name: string | null;
    is_natural_person: boolean;
}

export interface DriverOption {
    id: number;
    first_name: string;
    first_lastname: string;
    identification_number: string;
    license_due_at: string | null;
    license_due_date: string | null;
    timezone?: string | null;
    eps_id: number | null;
    pension_fund_id: number | null;
}

export interface ContractOption {
    id: number;
    contract_number: string;
    third_party_id: number;
    contract_object: string;
    /** UTC instant marking start of contract in the contract's timezone. */
    start_at: string;
    /** Half-open UTC instant: contract is active when service.planned_start_at < end_at. */
    end_at: string;
    timezone: string;
    is_generic: boolean;
    billing_unit_type: string | null;
    third_party?: ThirdPartyOption | null;
}

export interface ServiceFormData {
    contract_id: string;
    vehicle_id: string;
    driver_id: string;
    origin_municipality_id: string;
    origin_address: string;
    /** "lat,lng" pair captured from a Google Places pick or a manual map pin. Empty when the operator typed the address without confirming a location. */
    origin_coordinates: string;
    /** 'google' | 'manual' | '' — discriminator for `origin_coordinates`. */
    origin_coordinates_source: string;
    /** Google Geocoder location_type when source is 'google' (ROOFTOP/...). Empty for manual or legacy. */
    origin_coordinates_accuracy: string;
    /** Google Place ID when source is 'google'. Empty for manual pins or legacy. */
    origin_place_id: string;
    destination_municipality_id: string;
    destination_address: string;
    /** "lat,lng" pair captured from a Google Places pick or a manual pin. */
    destination_coordinates: string;
    destination_coordinates_source: string;
    destination_coordinates_accuracy: string;
    /** Google Place ID when source is 'google'. Empty for manual pins or legacy. */
    destination_place_id: string;
    /** Wall-clock `Y-m-d H:i` in the service's timezone. */
    planned_start: string;
    /** Wall-clock `Y-m-d H:i`; derived from start + duration (or edited directly). */
    planned_end: string;
    planned_duration: string;
    /** Wall-clock `Y-m-d H:i`; empty until the service is closed. */
    actual_start: string;
    /** Wall-clock `Y-m-d H:i`; may fall on a later day than actual_start. */
    actual_end: string;
    unit_value: string;
    quantity: string;
    billing_groups: string[];
    payment_method: string;
    service_status: string;
    justification: string;
    manual_entry_justification: string;
}

/**
 * Parse a "lat,lng" string back into a {lat, lng} object. Returns null
 * for empty input or values that don't match the regex enforced by
 * ServiceStoreRequest.
 */
function parseCoordsString(value: string): { lat: number; lng: number } | null {
    if (!value) return null;
    const match = /^(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/.exec(value.trim());
    if (!match) return null;
    const lat = Number(match[1]);
    const lng = Number(match[2]);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
    return { lat, lng };
}

/**
 * Resolve the picked municipality's centroid (lat/lng + display name) for
 * use as the map picker's `initialCenter` and `municipalityHint`. Returns
 * null if no municipality is selected or its coords are missing. The
 * LocationField derives its own Google location bias internally — this
 * helper exists solely for the map-picker modal.
 */
function useMunicipalityCenter(
    municipalities: MunicipalityOption[],
    selectedId: string,
): {
    latitude: number;
    longitude: number;
    cityName: string | null;
} | null {
    return useMemo(() => {
        if (!selectedId) return null;
        const m = municipalities.find(
            (x) => String(x.id) === String(selectedId),
        );
        if (!m || m.latitude == null || m.longitude == null) return null;
        const latitude = Number(m.latitude);
        const longitude = Number(m.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            return null;
        }
        return { latitude, longitude, cityName: m.name ?? null };
    }, [municipalities, selectedId]);
}

/**
 * Map each PaymentMethod value to its representative lucide icon. Kept
 * next to the form so the icon choice stays close to the Choicebox
 * rendering, but it could move to PaymentMethod.ts if other surfaces
 * end up needing the same visual cue (e.g. invoice listings).
 */
const PaymentMethodIcon: Record<
    PaymentMethod,
    React.ComponentType<{ className?: string; 'aria-hidden'?: boolean }>
> = {
    [PaymentMethod.Credit]: CreditCard,
    [PaymentMethod.Cash]: Banknote,
    [PaymentMethod.Transfer]: ArrowRightLeft,
};

function thirdPartyLabel(tp: ThirdPartyOption): string {
    if (tp.is_natural_person) {
        return `${tp.first_name} ${tp.first_lastname} (${tp.identification_number})`;
    }
    return `${tp.company_name} (${tp.identification_number})`;
}

/**
 * Short display label for a contract's client — company name when
 * available, full natural-person name otherwise, or empty string when
 * the contract has no associated tercero.
 */
function contractClientLabel(c: ContractOption): string {
    if (!c.third_party) return '';
    if (c.third_party.is_natural_person) {
        return `${c.third_party.first_name ?? ''} ${c.third_party.first_lastname ?? ''}`.trim();
    }
    return c.third_party.company_name ?? '';
}

/**
 * Format a UTC instant ISO string as Y-m-d in the contract's timezone.
 * Used to display the contract's vigencia/end date in the combobox row.
 */
function formatContractDate(isoUtc: string, timezone: string): string {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date(isoUtc));
    } catch {
        return isoUtc.slice(0, 10);
    }
}

/**
 * Bucket a driver's license expiry date into a UI severity. Returns
 * null when the license is valid for more than 30 days (no badge shown),
 * `soon` when within 30 days, `expired` when on or before today. Dates
 * are compared as Y-m-d strings in the given timezone (the operator's
 * operation TZ by default — see callsite).
 */
function driverLicenseStatus(
    licenseDueDate: string | null,
    timezone: string,
): { severity: 'soon' | 'expired'; label: string } | null {
    if (!licenseDueDate) return null;
    const today = viewerToday(timezone);
    if (licenseDueDate <= today) {
        return { severity: 'expired', label: 'Licencia vencida' };
    }
    // 30-day window
    const d = new Date(`${licenseDueDate}T00:00:00Z`);
    const t = new Date(`${today}T00:00:00Z`);
    const diffDays = Math.round((d.getTime() - t.getTime()) / 86_400_000);
    if (diffDays <= 30) {
        return {
            severity: 'soon',
            label: `Licencia vence en ${diffDays} día${diffDays === 1 ? '' : 's'}`,
        };
    }
    return null;
}

/**
 * Wall-clock minute math. Both inputs are `Y-m-d H:i` strings interpreted
 * as the same (service) timezone, so the difference is computed via
 * `Date.UTC` purely from the calendar components — no local-TZ or DST
 * skew, and a window that crosses midnight yields a correct positive
 * duration.
 */
function wallClockToMinutes(value: string): number | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(
        value || '',
    );
    if (!match) return null;
    const [, y, mo, d, h, mi] = match.map(Number);
    return Date.UTC(y, mo - 1, d, h, mi) / 60_000;
}

function minutesToWallClock(totalMinutes: number): string {
    const d = new Date(totalMinutes * 60_000);
    const pad = (n: number) => String(n).padStart(2, '0');
    return (
        `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}` +
        ` ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`
    );
}

function addMinutesWc(start: string, mins: number): string | null {
    const base = wallClockToMinutes(start);
    if (base === null || !Number.isFinite(mins)) return null;
    return minutesToWallClock(base + mins);
}

function diffMinutesWc(start: string, end: string): number | null {
    const s = wallClockToMinutes(start);
    const e = wallClockToMinutes(end);
    if (s === null || e === null) return null;
    const diff = e - s;
    return diff > 0 ? diff : null;
}

function computeActualDuration(start: string, end: string): string | null {
    const diff = diffMinutesWc(start, end);
    return diff === null ? null : `${diff} min`;
}

/**
 * Native-Intl projection of a wall-clock day + time-of-day in
 * `timezone` to a UTC instant ISO string. Returns null when inputs are
 * incomplete or unparseable.
 */
function projectToUtcIso(
    date: string,
    time: string,
    timezone: string,
): string | null {
    if (!date || !time) return null;
    const [y, mo, d] = date.split('-').map(Number);
    const [h, m] = time.split(':').map(Number);
    if (!y || !mo || !d || Number.isNaN(h) || Number.isNaN(m)) return null;

    // Compute the offset of the target wall-clock in the target TZ by
    // formatting an arbitrary UTC instant and reading back the parts.
    // This avoids pulling in date-fns-tz (per REQ AC-7).
    const utcGuess = Date.UTC(y, mo - 1, d, h, m);
    const fmt = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
    const parts = fmt.formatToParts(new Date(utcGuess));
    const map: Record<string, string> = {};
    for (const p of parts) map[p.type] = p.value;
    const projected = Date.UTC(
        Number(map.year),
        Number(map.month) - 1,
        Number(map.day),
        Number(map.hour),
        Number(map.minute),
    );
    const offset = projected - utcGuess;
    const realUtc = utcGuess - offset;
    return new Date(realUtc).toISOString();
}

/**
 * Render a wall-clock `HH:mm` (24h) string as 12-hour with a Spanish
 * am/pm suffix (e.g. "22:00" → "10:00 p. m."). Presentation only — the
 * stored/posted value stays 24h.
 */
function to12h(time: string): string {
    const match = /^(\d{1,2}):(\d{2})/.exec(time);
    if (!match) {
        return time;
    }
    const d = new Date(2000, 0, 1, Number(match[1]), Number(match[2]));
    return formatDate(d, 'hh:mm a', { locale: es });
}

/**
 * Read-only confirmation rendered next to the planned-start-time
 * picker so operators can see exactly what's about to be persisted
 * before submitting. Sourced from the contract's TZ when available,
 * else config('app.operation_tz') via Inertia shared props.
 */
function ScheduleTimezoneHint({
    date,
    time,
    timezone,
}: {
    date: string;
    time: string;
    timezone: string;
}) {
    if (!date || !time) {
        return (
            <p className="text-xs text-muted-foreground">
                Zona horaria del servicio: <strong>{timezone}</strong>.
            </p>
        );
    }
    const utcIso = projectToUtcIso(date, time, timezone);
    return (
        <p className="text-xs text-muted-foreground">
            Se guardará como{' '}
            <strong>
                {date} {to12h(time)} {timezone}
            </strong>
            {utcIso ? <> → {utcIso}</> : null}.
        </p>
    );
}

/**
 * Thin wrapper over the shadcn DateTimePicker that speaks the form's
 * wall-clock `Y-m-d H:i` string contract. The picker's `Date` is treated
 * as a TZ-naive wall-clock carrier (see wallClockToDate/dateToWallClock);
 * the backend re-projects it into the service's IANA timezone. Rendered
 * in 24-hour format with a Spanish, Monday-first calendar.
 */
function ScheduleDateTimeField({
    value,
    onChange,
    min,
    max,
    disabled,
    invalid,
}: {
    value: string;
    onChange: (value: string) => void;
    min?: Date;
    max?: Date;
    disabled?: boolean;
    invalid?: boolean;
}) {
    return (
        <DateTimePicker
            value={wallClockToDate(value)}
            onChange={(date) => onChange(dateToWallClock(date))}
            min={min}
            max={max}
            disabled={disabled}
            locale={es}
            weekStartsOn={1}
            use12HourFormat
            timePicker={{ hour: true, minute: true, second: false }}
            renderTrigger={({ value: triggerValue }) => (
                <button
                    type="button"
                    disabled={disabled}
                    aria-invalid={invalid}
                    className={cn(
                        'flex h-9 w-full items-center rounded-md border border-input bg-transparent px-3 text-left text-sm shadow-sm',
                        'focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none',
                        'disabled:cursor-not-allowed disabled:opacity-50',
                        !triggerValue && 'text-muted-foreground',
                        invalid && 'border-destructive',
                    )}
                >
                    <CalendarClock className="mr-2 size-4 shrink-0" />
                    {triggerValue
                        ? formatDate(triggerValue, "d 'de' MMM yyyy, hh:mm a", {
                              locale: es,
                          })
                        : 'Seleccionar fecha y hora'}
                </button>
            )}
        />
    );
}

interface ServiceFormProps {
    data: ServiceFormData;
    setData: <K extends keyof ServiceFormData>(
        key: K,
        value: ServiceFormData[K],
    ) => void;
    errors: Partial<Record<keyof ServiceFormData, string>>;
    vehicles: VehicleOption[];
    drivers: DriverOption[];
    contracts: ContractOption[];
    municipalities: MunicipalityOption[];
    incidentCount?: number;
    mode: 'create' | 'edit';
    /**
     * User-facing consecutive shown read-only at the top of "Datos del
     * Servicio". In create mode it is the number reserved when the form
     * opened; in edit mode it is the persisted `service.service_number`.
     */
    serviceNumber?: string | null;
    dayStatus?: DayStatus | null;
    canEditExecuted?: boolean;
    isAdmin?: boolean;
    /**
     * Bubble up a "should block submit" signal so the page can disable
     * the Save button. True when:
     *  - any address has a place-details fetch in flight, OR
     *  - any address has text but no captured coordinates (operator
     *    must pick a Google suggestion or drop a manual pin first).
     */
    onAddressCommitInFlight?: (inFlight: boolean) => void;
    /**
     * When provided, render a "+" button next to the Contrato picker that
     * the parent uses to open a ContractDialog in create mode. Parent owns
     * the dialog state and the flash-data watcher that auto-selects after
     * create. See ServicesCreate for the wiring.
     */
    onCreateContractClick?: () => void;
}

export default function ServiceForm({
    data,
    setData,
    errors,
    vehicles,
    drivers,
    contracts,
    municipalities,
    incidentCount,
    mode,
    serviceNumber,
    dayStatus,
    canEditExecuted,
    isAdmin,
    onAddressCommitInFlight,
    onCreateContractClick,
}: ServiceFormProps) {
    const invalid = (field: keyof ServiceFormData) =>
        errors[field] ? true : undefined;

    const isExecutedDay = dayStatus?.status === 'executed';
    const isFullyLocked = isExecutedDay && !canEditExecuted && !isAdmin;
    const isBillingOnly = isExecutedDay && canEditExecuted && !isAdmin;
    const isAdminEdit = isExecutedDay && isAdmin;

    const billingFields = new Set([
        'billing_groups',
        'unit_value',
        'quantity',
        'payment_method',
    ]);
    const isFieldDisabled = (field: string) => {
        if (isFullyLocked) return true;
        if (isBillingOnly) return !billingFields.has(field);
        return false;
    };

    const selectedVehicle = useMemo(
        () => vehicles.find((v) => v.id === Number(data.vehicle_id)) ?? null,
        [vehicles, data.vehicle_id],
    );

    const selectedDriver = useMemo(
        () => drivers.find((d) => d.id === Number(data.driver_id)) ?? null,
        [drivers, data.driver_id],
    );

    const originCenter = useMunicipalityCenter(
        municipalities,
        data.origin_municipality_id,
    );
    const destinationCenter = useMunicipalityCenter(
        municipalities,
        data.destination_municipality_id,
    );

    const [originPickerOpen, setOriginPickerOpen] = useState(false);
    const [destinationPickerOpen, setDestinationPickerOpen] = useState(false);
    // Set when the map picker auto-match step (reverse-geocoded
    // place.name) fails to find a row in the DANE catalog. The
    // LocationField surfaces an amber warning so the operator picks
    // the city manually. Cleared on the next municipality change.
    const [originPickerNoMatch, setOriginPickerNoMatch] = useState(false);
    const [destinationPickerNoMatch, setDestinationPickerNoMatch] =
        useState(false);

    // Counter — multiple commits could be in flight (origin + destination).
    // The form's submit button stays disabled while any are pending. Counter
    // semantics keeps the implementation race-free even if both inputs
    // overlap their permanent fetches.
    const [commitCount, setCommitCount] = useState(0);
    const handleOriginCommit = useCallback((inFlight: boolean) => {
        setCommitCount((n) => Math.max(0, n + (inFlight ? 1 : -1)));
    }, []);
    const handleDestinationCommit = useCallback((inFlight: boolean) => {
        setCommitCount((n) => Math.max(0, n + (inFlight ? 1 : -1)));
    }, []);

    // An address that has text but no captured coordinates is invalid:
    // the operator typed something but never picked a Google suggestion
    // or placed a manual pin, so we cannot persist a usable location.
    // Backend mirrors this with `required_with` rules on coordinates and
    // coordinates_source — frontend just blocks the Save button to give
    // immediate feedback.
    const addressNeedsConfirmation =
        (data.origin_address.trim().length > 0 && !data.origin_coordinates) ||
        (data.destination_address.trim().length > 0 &&
            !data.destination_coordinates);

    // The origin/destination city is required (the precise address/pin is
    // optional — the backend fills the city centroid when omitted). Block
    // Save until both cities are picked, mirroring the backend rule.
    const cityMissing =
        !data.origin_municipality_id || !data.destination_municipality_id;

    useEffect(() => {
        onAddressCommitInFlight?.(
            commitCount > 0 || addressNeedsConfirmation || cityMissing,
        );
    }, [
        commitCount,
        addressNeedsConfirmation,
        cityMissing,
        onAddressCommitInFlight,
    ]);

    const filteredContracts = useMemo(() => {
        // The service date is now derived from the planned-start datetime
        // (the standalone "Fecha del Servicio" input was removed).
        const serviceDate = data.planned_start.slice(0, 10);
        if (!serviceDate) return contracts;
        // Project the operator's selected wall-clock service start into
        // a UTC instant using the contract's TZ — same pattern Service
        // backend uses. Half-open interval: [start_at, end_at). Falls
        // back to the day's start in the contract's TZ when only a date
        // is present.
        const time = data.planned_start.slice(11, 16) || '00:00';
        return contracts.filter((c) => {
            const instantIso = projectToUtcIso(serviceDate, time, c.timezone);
            if (!instantIso) return true;
            return c.start_at <= instantIso && c.end_at > instantIso;
        });
    }, [contracts, data.planned_start]);

    const driverMissingSocialSecurity =
        selectedDriver &&
        (selectedDriver.eps_id === null ||
            selectedDriver.pension_fund_id === null);

    const actualDuration = computeActualDuration(
        data.actual_start,
        data.actual_end,
    );

    const isClosed = data.service_status === 'closed';

    // Linked Horarios control: planned_end is derived from planned_start +
    // planned_duration, but the operator can also edit the end datetime
    // directly (which recomputes duration). lastEditedRef breaks the
    // otherwise-circular update. Unlike the legacy time-only control, the
    // planned end may legitimately fall on a later calendar day than the
    // start (a window that crosses midnight).
    const lastEditedRef = useRef<'start' | 'duration' | 'end' | null>(null);

    useEffect(() => {
        if (lastEditedRef.current === 'end') return;
        if (!data.planned_start) return;
        const mins = Number(data.planned_duration);
        if (!Number.isFinite(mins) || mins <= 0) {
            if (data.planned_end !== '') setData('planned_end', '');
            return;
        }
        const next = addMinutesWc(data.planned_start, mins);
        if (next && next !== data.planned_end) setData('planned_end', next);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.planned_start, data.planned_duration]);

    const handlePlannedStartChange = (value: string) => {
        lastEditedRef.current = 'start';
        setData('planned_start', value);
    };

    const handlePlannedDurationChange = (value: string) => {
        lastEditedRef.current = 'duration';
        setData('planned_duration', value);
    };

    const handlePlannedEndChange = (value: string) => {
        lastEditedRef.current = 'end';
        setData('planned_end', value);
        const mins = diffMinutesWc(data.planned_start, value);
        if (mins !== null) setData('planned_duration', String(mins));
    };

    // ETA hint from Google Routes (curated cache → live fallback). Only
    // fetched when both coords are present and not currently in flight
    // for the same pair. Auto-applies to planned_duration when the field
    // is still empty/zero so create-mode starts pre-filled; in edit mode
    // (or after the operator typed something) the existing value wins —
    // the Aplicar button is the explicit revert.
    const [eta, setEta] = useState<{ key: string; minutes: number } | null>(
        null,
    );
    // Derive the visible ETA during render: it applies only when both coords
    // are present AND match the pair the value was fetched for. This removes
    // the need to synchronously reset state inside the fetch effect when the
    // coords become incomplete or change to a different pair.
    const etaCoordsKey =
        data.origin_coordinates && data.destination_coordinates
            ? `${data.origin_coordinates}|${data.destination_coordinates}`
            : null;
    const etaMinutes =
        etaCoordsKey && eta?.key === etaCoordsKey ? eta.minutes : null;
    const etaAbortRef = useRef<AbortController | null>(null);
    // Mirror of data.planned_duration kept in a ref so the async ETA
    // resolver can read the up-to-date value (the .then closure would
    // otherwise see the value at effect-trigger time).
    const durationRef = useRef(data.planned_duration);
    useEffect(() => {
        durationRef.current = data.planned_duration;
    }, [data.planned_duration]);

    useEffect(() => {
        const o = data.origin_coordinates;
        const d = data.destination_coordinates;
        if (!o || !d) {
            // No synchronous reset needed: `etaMinutes` is derived in render
            // and is already null when the coords are incomplete.
            etaAbortRef.current?.abort();
            return;
        }
        etaAbortRef.current?.abort();
        const ctrl = new AbortController();
        etaAbortRef.current = ctrl;
        const url = ServiceController.eta({
            query: {
                origin_coordinates: o,
                destination_coordinates: d,
            },
        }).url;
        fetch(url, {
            signal: ctrl.signal,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((res: { eta_minutes: number | null } | null) => {
                if (res && typeof res.eta_minutes === 'number') {
                    const n = res.eta_minutes;
                    setEta({ key: `${o}|${d}`, minutes: n });
                    const currentRaw = durationRef.current;
                    const currentNum = Number(currentRaw);
                    const isEmpty =
                        !currentRaw ||
                        !Number.isFinite(currentNum) ||
                        currentNum <= 0;
                    if (isEmpty) {
                        lastEditedRef.current = 'duration';
                        setData('planned_duration', String(n));
                    }
                } else {
                    setEta(null);
                }
            })
            .catch((err) => {
                if (
                    err &&
                    typeof err === 'object' &&
                    'name' in err &&
                    (err as { name?: string }).name === 'AbortError'
                ) {
                    return;
                }
                setEta(null);
            });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.origin_coordinates, data.destination_coordinates]);

    const applyEta = () => {
        if (etaMinutes === null) return;
        lastEditedRef.current = 'duration';
        setData('planned_duration', String(etaMinutes));
    };

    const currentDurationNum = Number(data.planned_duration);
    const etaMatchesCurrent =
        etaMinutes !== null &&
        Number.isFinite(currentDurationNum) &&
        currentDurationNum === etaMinutes;

    // REQ-011 billing-unit semantics. Look up the selected contract's
    // billing_unit_type and derive the Cantidad label + hint so the
    // operator knows whether they're entering trips, passengers, days,
    // or hours. Legacy contracts with a null billing_unit_type fall back
    // to the generic "Cantidad (unidades del contrato)" label.
    const selectedContract = contracts.find(
        (c) => String(c.id) === String(data.contract_id),
    );
    const billingUnitLabel = (() => {
        switch (selectedContract?.billing_unit_type) {
            case 'viaje':
                return 'Cantidad (viajes)';
            case 'pasajero':
                return 'Cantidad (pasajeros)';
            case 'dia':
                return 'Cantidad (días)';
            case 'hora':
                return 'Cantidad (horas)';
            default:
                return 'Cantidad (unidades del contrato)';
        }
    })();
    const billingUnitHint = selectedContract
        ? selectedContract.billing_unit_type
            ? `Contrato ${selectedContract.contract_number} factura por ${selectedContract.billing_unit_type}.`
            : `Contrato ${selectedContract.contract_number} no tiene unidad de facturación definida.`
        : 'Seleccione un contrato para conocer la unidad de cobro.';

    // Resolve the service's IANA timezone for the schedule confirmation
    // hint near the time picker. Source priority matches the backend's
    // ServiceStoreRequest::resolveServiceTimezone — selected contract
    // first (when contract carries a TZ column), else the
    // operator-default operation_tz from the Inertia shared props.
    const sharedConfig = usePage().props.config as
        | { operation_tz?: string }
        | undefined;
    const operationTz = sharedConfig?.operation_tz ?? 'America/Bogota';
    const contractTimezone = selectedContract?.timezone ?? null;
    const resolvedTimezone = contractTimezone ?? operationTz;

    // REQ-009 retroactive-entry gate. Backend rejects service_date >=
    // today + closed; allows service_date < today + closed but only
    // with a justification. F-004 fix: anchor "today" in operation TZ
    // (matches the backend's `Tz::nowIn(operation_tz)`); the legacy
    // `new Date().toISOString().slice(0,10)` used browser-UTC and
    // drifted in the evening Bogotá hours.
    const todayIso = viewerToday(operationTz);
    // Service date derived from the planned-start datetime (the standalone
    // date input was removed).
    const serviceDate = data.planned_start.slice(0, 10);
    const isPastDate =
        mode === 'create' && serviceDate !== '' && serviceDate < todayIso;
    const isFutureOrToday =
        mode === 'create' && serviceDate !== '' && serviceDate >= todayIso;
    // Floor for the planned-start picker: block scheduling an open service
    // in the past on create. Relaxed for edits and for closed (retroactive)
    // records, which the backend governs via validateRetroactiveEntry.
    const plannedStartMinDate =
        mode === 'create' && !isClosed
            ? wallClockToDate(`${todayIso} 00:00`)
            : undefined;
    const requiresRetroactiveJustification =
        mode === 'create' && isPastDate && isClosed;
    const illegalCreateAsClosed =
        mode === 'create' && isFutureOrToday && isClosed;

    // Tolerance window for the actual (executed) times: the backend bounds
    // each actual instant to [planned_start − 6h, planned_end + 6h] (see
    // ACTUAL_TOLERANCE_HOURS). The picker bounds below are intentionally
    // DAY-granular and generous — they grey out days clearly outside the
    // window (catching gross typos) without ever blocking a valid time on a
    // boundary day. The precise ±6h check stays server-side, where the
    // error message lives. Bounds are undefined until the planned window is
    // set, leaving the pickers unconstrained.
    const ACTUAL_TOLERANCE_MS = 6 * 60 * 60 * 1000;
    const plannedStartDate = wallClockToDate(data.planned_start);
    const plannedEndDate = wallClockToDate(data.planned_end);
    const actualLowerBound = plannedStartDate
        ? startOfDay(new Date(plannedStartDate.getTime() - ACTUAL_TOLERANCE_MS))
        : undefined;
    const actualUpperBound = plannedEndDate
        ? endOfDay(new Date(plannedEndDate.getTime() + ACTUAL_TOLERANCE_MS))
        : undefined;
    // Actual-end floor: the executed end must follow the executed start, but
    // keep it day-granular so a same-day later time stays selectable (the
    // strict end-after-start check is enforced on the server).
    const actualStartDate = wallClockToDate(data.actual_start);
    const actualEndMin = actualStartDate
        ? startOfDay(actualStartDate)
        : actualLowerBound;

    return (
        // One APIProvider for the whole form so both LocationFields and
        // both MapPickerModals share a single Google Maps JS load.
        <APIProvider apiKey={GOOGLE_MAPS_BROWSER_KEY}>
            {isFullyLocked && (
                <Alert variant="destructive">
                    <Lock className="size-4" />
                    <AlertTitle>Día ejecutado</AlertTitle>
                    <AlertDescription>
                        Este día está ejecutado. No se pueden modificar los
                        servicios.
                    </AlertDescription>
                </Alert>
            )}

            {isBillingOnly && (
                <Alert>
                    <Info className="size-4" />
                    <AlertTitle>Día ejecutado</AlertTitle>
                    <AlertDescription>
                        Día ejecutado. Solo puede modificar los campos de
                        facturación.
                    </AlertDescription>
                </Alert>
            )}

            {isAdminEdit && (
                <Alert>
                    <ShieldAlert className="size-4" />
                    <AlertTitle>Día ejecutado</AlertTitle>
                    <AlertDescription>
                        Está editando un servicio en un día ejecutado. La
                        modificación requiere justificación obligatoria y
                        quedará registrada en la auditoría.
                    </AlertDescription>
                </Alert>
            )}

            {illegalCreateAsClosed && (
                <Alert variant="destructive">
                    <AlertTriangle className="size-4" />
                    <AlertTitle>
                        No se permite crear un servicio Cerrado para hoy o una
                        fecha futura
                    </AlertTitle>
                    <AlertDescription>
                        Cambie el estado a Abierto. Una vez ejecutado, el
                        conductor cerrará el servicio desde su portal.
                    </AlertDescription>
                </Alert>
            )}

            {requiresRetroactiveJustification && (
                <Alert>
                    <Info className="size-4" />
                    <AlertTitle>Registro retroactivo</AlertTitle>
                    <AlertDescription>
                        Está registrando un servicio cerrado de una fecha
                        anterior. Indique por qué se registra manualmente fuera
                        del flujo del conductor — quedará en la auditoría.
                    </AlertDescription>
                </Alert>
            )}

            {/* Datos del Servicio */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        Datos del Servicio
                        {mode === 'edit' &&
                            incidentCount !== undefined &&
                            incidentCount > 0 && (
                                <Badge variant="destructive">
                                    {incidentCount} novedad
                                    {incidentCount > 1 ? 'es' : ''}
                                </Badge>
                            )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    {serviceNumber ? (
                        <div className="grid gap-2">
                            <Label htmlFor="service_number">Consecutivo</Label>
                            <div
                                id="service_number"
                                className="flex h-9 w-fit items-center rounded-md bg-muted px-3 font-mono text-base font-semibold tracking-wide tabular-nums"
                                aria-label="Consecutivo del servicio"
                            >
                                {serviceNumber}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {mode === 'create'
                                    ? 'Reservado al abrir; se confirma al guardar.'
                                    : 'Identificador del servicio.'}
                            </p>
                        </div>
                    ) : null}
                    <div className="grid gap-4 md:grid-cols-2 md:grid-rows-[auto_1fr_auto]">
                        <div
                            className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={invalid('contract_id')}
                        >
                            <Label htmlFor="contract_id">Contrato *</Label>
                            {/* The flex row is a grid item; grid items
                             * default to `min-width: auto`, which refuses
                             * to shrink below content. Without `min-w-0`
                             * here, a long contract label widens the
                             * trigger, pushes the "+" button out of the
                             * grid column, and overlaps the next field
                             * (Estado). Companion `min-w-0` on the
                             * flex-1 child lets the combobox's own
                             * truncate take effect. */}
                            <div className="flex min-w-0 gap-2">
                                <div className="min-w-0 flex-1">
                                    <SearchableCombobox<ContractOption>
                                        id="contract_id"
                                        name="contract_id"
                                        items={filteredContracts}
                                        value={data.contract_id}
                                        onChange={(value) =>
                                            setData('contract_id', value)
                                        }
                                        getKey={(c) => String(c.id)}
                                        getSearchText={(c) =>
                                            `${c.contract_number} ${contractClientLabel(c)} ${c.contract_object ?? ''}`
                                        }
                                        renderTrigger={(c) => (
                                            <span className="truncate">
                                                <span className="font-medium">
                                                    {c.contract_number}
                                                </span>
                                                {' — '}
                                                {contractClientLabel(c)}
                                            </span>
                                        )}
                                        renderItem={(c) => (
                                            <div className="flex flex-col gap-0.5">
                                                <div className="flex items-baseline gap-1">
                                                    <span className="font-medium">
                                                        {c.contract_number}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                    <span className="truncate">
                                                        {contractClientLabel(c)}
                                                    </span>
                                                </div>
                                                <div className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                                    {c.billing_unit_type && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="font-normal"
                                                        >
                                                            {
                                                                c.billing_unit_type
                                                            }
                                                        </Badge>
                                                    )}
                                                    <span>
                                                        vigente hasta{' '}
                                                        {formatContractDate(
                                                            c.end_at,
                                                            c.timezone,
                                                        )}
                                                    </span>
                                                </div>
                                            </div>
                                        )}
                                        placeholder="Seleccionar contrato..."
                                        searchPlaceholder="Buscar contrato…"
                                        emptyText="Sin contratos vigentes."
                                        disabled={isFieldDisabled(
                                            'contract_id',
                                        )}
                                        invalid={invalid('contract_id')}
                                    />
                                </div>
                                {onCreateContractClick && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        onClick={onCreateContractClick}
                                        disabled={isFieldDisabled(
                                            'contract_id',
                                        )}
                                        aria-label="Crear nuevo contrato"
                                        title="Crear nuevo contrato"
                                    >
                                        <Plus className="size-4" />
                                    </Button>
                                )}
                            </div>
                            <FieldFooter error={errors.contract_id} />
                        </div>
                        <div
                            className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={invalid('service_status')}
                        >
                            <Label htmlFor="service_status">Estado *</Label>
                            <ToggleGroup
                                id="service_status"
                                type="single"
                                variant="outline"
                                value={data.service_status}
                                onValueChange={(value) => {
                                    // Radix ToggleGroup type=single fires '' when
                                    // the user de-selects the active item by
                                    // clicking it again. Keep the current value
                                    // in that case — Estado must always have a
                                    // selection (the form posts a required field).
                                    if (!value) return;
                                    setData('service_status', value);
                                    // Leaving 'closed' hides the Reales block; clear
                                    // any captured actual times so we don't persist
                                    // execution data for a non-executed service.
                                    if (value !== 'closed') {
                                        if (data.actual_start) {
                                            setData('actual_start', '');
                                        }
                                        if (data.actual_end) {
                                            setData('actual_end', '');
                                        }
                                    }
                                }}
                                disabled={isFieldDisabled('service_status')}
                                aria-invalid={invalid('service_status')}
                                className="w-full justify-stretch"
                            >
                                {Object.entries(ServiceStatus).map(
                                    ([key, value]) => (
                                        <ToggleGroupItem
                                            key={key}
                                            value={value}
                                            className="flex-1"
                                        >
                                            {ServiceStatusLabel[value]}
                                        </ToggleGroupItem>
                                    ),
                                )}
                            </ToggleGroup>
                            <FieldFooter error={errors.service_status} />
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-3 md:grid-rows-[auto_1fr_auto]">
                        <div
                            className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={invalid('vehicle_id')}
                        >
                            <Label htmlFor="vehicle_id">Vehículo *</Label>
                            <SearchableCombobox<VehicleOption>
                                id="vehicle_id"
                                name="vehicle_id"
                                items={vehicles}
                                value={data.vehicle_id}
                                onChange={(value) => {
                                    setData('vehicle_id', value);
                                    const v = vehicles.find(
                                        (v) => v.id === Number(value),
                                    );
                                    if (v?.is_third_party) {
                                        setData('driver_id', '');
                                    }
                                }}
                                getKey={(v) => String(v.id)}
                                getSearchText={(v) =>
                                    `${v.plate} ${v.vehicle_type?.name ?? ''} ${v.third_party ? thirdPartyLabel(v.third_party) : ''}`
                                }
                                renderTrigger={(v) => (
                                    <span className="flex min-w-0 items-center gap-2">
                                        <span className="font-mono">
                                            {v.plate}
                                        </span>
                                        {v.vehicle_type && (
                                            <span className="truncate text-xs text-muted-foreground">
                                                · {v.vehicle_type.name}
                                            </span>
                                        )}
                                        {v.is_third_party && (
                                            <Badge
                                                variant="secondary"
                                                className="font-normal"
                                            >
                                                Tercero
                                            </Badge>
                                        )}
                                    </span>
                                )}
                                renderItem={(v) => (
                                    <div className="flex flex-col gap-0.5">
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono">
                                                {v.plate}
                                            </span>
                                            {v.vehicle_type && (
                                                <Badge
                                                    variant="outline"
                                                    className="font-normal"
                                                >
                                                    {v.vehicle_type.name}
                                                </Badge>
                                            )}
                                            {v.is_third_party && (
                                                <Badge
                                                    variant="secondary"
                                                    className="font-normal"
                                                >
                                                    Tercero
                                                </Badge>
                                            )}
                                        </div>
                                        {v.third_party && (
                                            <span className="truncate text-xs text-muted-foreground">
                                                {thirdPartyLabel(v.third_party)}
                                            </span>
                                        )}
                                    </div>
                                )}
                                placeholder="Seleccionar vehículo..."
                                searchPlaceholder="Buscar por placa, tipo o tercero…"
                                emptyText="Sin vehículos."
                                disabled={isFieldDisabled('vehicle_id')}
                                invalid={invalid('vehicle_id')}
                            />
                            <FieldFooter error={errors.vehicle_id} />
                        </div>

                        {selectedVehicle?.is_third_party ? (
                            <div className="group/field grid gap-2 md:col-span-2 md:row-span-3 md:grid-rows-subgrid">
                                <Label>Proveedor (Tercero)</Label>
                                <div className="flex items-center rounded-md border bg-muted/50 px-3 py-2 text-sm">
                                    {selectedVehicle.third_party
                                        ? thirdPartyLabel(
                                              selectedVehicle.third_party,
                                          )
                                        : 'Sin tercero asociado'}
                                </div>
                                <div />
                            </div>
                        ) : (
                            <div
                                className="group/field grid gap-2 md:col-span-2 md:row-span-3 md:grid-rows-subgrid"
                                data-error={invalid('driver_id')}
                            >
                                <div className="flex items-center gap-2">
                                    <Label htmlFor="driver_id">Conductor</Label>
                                    {driverMissingSocialSecurity && (
                                        <TooltipProvider>
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <AlertTriangle className="size-4 text-amber-500" />
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    El conductor no tiene
                                                    seguridad social completa
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    )}
                                </div>
                                <SearchableCombobox<DriverOption>
                                    id="driver_id"
                                    name="driver_id"
                                    items={drivers}
                                    value={data.driver_id}
                                    onChange={(value) =>
                                        setData('driver_id', value)
                                    }
                                    getKey={(d) => String(d.id)}
                                    getSearchText={(d) =>
                                        `${d.first_name} ${d.first_lastname} ${d.identification_number}`
                                    }
                                    renderTrigger={(d) => (
                                        <span className="truncate">
                                            {d.first_name} {d.first_lastname}{' '}
                                            <span className="text-muted-foreground">
                                                ({d.identification_number})
                                            </span>
                                        </span>
                                    )}
                                    renderItem={(d) => {
                                        const licenseStatus =
                                            driverLicenseStatus(
                                                d.license_due_date,
                                                operationTz,
                                            );
                                        const missingSocial =
                                            d.eps_id === null ||
                                            d.pension_fund_id === null;
                                        return (
                                            <div className="flex flex-col gap-0.5">
                                                <div className="flex items-baseline gap-1">
                                                    <span className="font-medium">
                                                        {d.first_name}{' '}
                                                        {d.first_lastname}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        (
                                                        {
                                                            d.identification_number
                                                        }
                                                        )
                                                    </span>
                                                </div>
                                                {(licenseStatus ||
                                                    missingSocial) && (
                                                    <div className="flex flex-wrap items-center gap-1 text-xs">
                                                        {licenseStatus && (
                                                            <Badge
                                                                variant={
                                                                    licenseStatus.severity ===
                                                                    'expired'
                                                                        ? 'destructive'
                                                                        : 'secondary'
                                                                }
                                                                className={cn(
                                                                    'font-normal',
                                                                    licenseStatus.severity ===
                                                                        'soon' &&
                                                                        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                                                                )}
                                                            >
                                                                {
                                                                    licenseStatus.label
                                                                }
                                                            </Badge>
                                                        )}
                                                        {missingSocial && (
                                                            <Badge
                                                                variant="outline"
                                                                className="font-normal text-muted-foreground"
                                                            >
                                                                Sin{' '}
                                                                {d.eps_id ===
                                                                null
                                                                    ? 'EPS'
                                                                    : 'Pensión'}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    }}
                                    placeholder="Seleccionar conductor..."
                                    searchPlaceholder="Buscar por nombre o cédula…"
                                    emptyText="Sin conductores."
                                    disabled={isFieldDisabled('driver_id')}
                                    invalid={invalid('driver_id')}
                                />
                                <FieldFooter error={errors.driver_id} />
                            </div>
                        )}
                    </div>
                </CardContent>
            </Card>

            {/* Origen y Destino */}
            <Card>
                <CardHeader>
                    <CardTitle>Origen y Destino</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid gap-6 md:grid-cols-2 md:grid-rows-[auto_auto_auto]">
                        <div
                            className="group/field grid min-w-0 gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={
                                invalid('origin_municipality_id') ||
                                invalid('origin_address')
                            }
                        >
                            <Label htmlFor="origin_address">Origen *</Label>
                            <LocationField
                                id="origin_address"
                                name="origin_address"
                                municipalities={municipalities}
                                municipalityId={data.origin_municipality_id}
                                address={data.origin_address}
                                coordinates={data.origin_coordinates}
                                coordinatesSource={
                                    data.origin_coordinates_source as CoordinatesSource
                                }
                                coordinatesAccuracy={
                                    data.origin_coordinates_accuracy
                                }
                                onMunicipalityChange={(val) => {
                                    setData('origin_municipality_id', val);
                                    setOriginPickerNoMatch(false);
                                }}
                                onAddressChange={(v) =>
                                    setData('origin_address', v)
                                }
                                onCoordinatesChange={(
                                    coords,
                                    source,
                                    accuracy,
                                    placeId,
                                ) => {
                                    setData('origin_coordinates', coords);
                                    setData(
                                        'origin_coordinates_source',
                                        coords ? source : '',
                                    );
                                    setData(
                                        'origin_coordinates_accuracy',
                                        accuracy ?? '',
                                    );
                                    setData(
                                        'origin_place_id',
                                        coords ? (placeId ?? '') : '',
                                    );
                                }}
                                onCommitInFlight={handleOriginCommit}
                                onOpenMapPicker={() =>
                                    setOriginPickerOpen(true)
                                }
                                pickerNoCityMatch={originPickerNoMatch}
                                invalidMunicipality={
                                    !!errors.origin_municipality_id
                                }
                                invalidAddress={!!errors.origin_address}
                                disabled={
                                    isFieldDisabled('origin_address') ||
                                    isFieldDisabled('origin_municipality_id')
                                }
                            />
                            <FieldFooter
                                error={
                                    errors.origin_municipality_id ||
                                    errors.origin_address ||
                                    errors.origin_coordinates
                                }
                            >
                                {data.origin_municipality_id &&
                                !data.origin_address.trim() &&
                                !data.origin_coordinates
                                    ? 'Se usará el centro de la ciudad como ubicación.'
                                    : null}
                            </FieldFooter>
                            <MapPickerModal
                                instanceLabel="origin"
                                open={originPickerOpen}
                                onOpenChange={setOriginPickerOpen}
                                initialCenter={
                                    originCenter
                                        ? {
                                              lat: originCenter.latitude,
                                              lng: originCenter.longitude,
                                          }
                                        : null
                                }
                                initialPin={parseCoordsString(
                                    data.origin_coordinates,
                                )}
                                addressHint={data.origin_address}
                                municipalityHint={
                                    originCenter?.cityName ?? null
                                }
                                onConfirm={({ coords, address, placeName }) => {
                                    setData('origin_address', address);
                                    setData(
                                        'origin_coordinates',
                                        `${coords.lat.toFixed(7)},${coords.lng.toFixed(7)}`,
                                    );
                                    setData(
                                        'origin_coordinates_source',
                                        'manual',
                                    );
                                    setData('origin_coordinates_accuracy', '');
                                    setData('origin_place_id', '');
                                    if (
                                        !data.origin_municipality_id &&
                                        placeName
                                    ) {
                                        const target = normalizeCity(placeName);
                                        const match = municipalities.find(
                                            (m) =>
                                                normalizeCity(m.name) ===
                                                target,
                                        );
                                        if (match) {
                                            setData(
                                                'origin_municipality_id',
                                                String(match.id),
                                            );
                                            setOriginPickerNoMatch(false);
                                        } else {
                                            setOriginPickerNoMatch(true);
                                        }
                                    }
                                    setOriginPickerOpen(false);
                                }}
                            />
                        </div>
                        <div
                            className="group/field grid min-w-0 gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={
                                invalid('destination_municipality_id') ||
                                invalid('destination_address')
                            }
                        >
                            <Label htmlFor="destination_address">
                                Destino *
                            </Label>
                            <LocationField
                                id="destination_address"
                                name="destination_address"
                                municipalities={municipalities}
                                municipalityId={
                                    data.destination_municipality_id
                                }
                                address={data.destination_address}
                                coordinates={data.destination_coordinates}
                                coordinatesSource={
                                    data.destination_coordinates_source as CoordinatesSource
                                }
                                coordinatesAccuracy={
                                    data.destination_coordinates_accuracy
                                }
                                onMunicipalityChange={(val) => {
                                    setData('destination_municipality_id', val);
                                    setDestinationPickerNoMatch(false);
                                }}
                                onAddressChange={(v) =>
                                    setData('destination_address', v)
                                }
                                onCoordinatesChange={(
                                    coords,
                                    source,
                                    accuracy,
                                    placeId,
                                ) => {
                                    setData('destination_coordinates', coords);
                                    setData(
                                        'destination_coordinates_source',
                                        coords ? source : '',
                                    );
                                    setData(
                                        'destination_coordinates_accuracy',
                                        accuracy ?? '',
                                    );
                                    setData(
                                        'destination_place_id',
                                        coords ? (placeId ?? '') : '',
                                    );
                                }}
                                onCommitInFlight={handleDestinationCommit}
                                onOpenMapPicker={() =>
                                    setDestinationPickerOpen(true)
                                }
                                pickerNoCityMatch={destinationPickerNoMatch}
                                invalidMunicipality={
                                    !!errors.destination_municipality_id
                                }
                                invalidAddress={!!errors.destination_address}
                                disabled={
                                    isFieldDisabled('destination_address') ||
                                    isFieldDisabled(
                                        'destination_municipality_id',
                                    )
                                }
                            />
                            <FieldFooter
                                error={
                                    errors.destination_municipality_id ||
                                    errors.destination_address ||
                                    errors.destination_coordinates
                                }
                            >
                                {data.destination_municipality_id &&
                                !data.destination_address.trim() &&
                                !data.destination_coordinates
                                    ? 'Se usará el centro de la ciudad como ubicación.'
                                    : null}
                            </FieldFooter>
                            <MapPickerModal
                                instanceLabel="destination"
                                open={destinationPickerOpen}
                                onOpenChange={setDestinationPickerOpen}
                                initialCenter={
                                    destinationCenter
                                        ? {
                                              lat: destinationCenter.latitude,
                                              lng: destinationCenter.longitude,
                                          }
                                        : null
                                }
                                initialPin={parseCoordsString(
                                    data.destination_coordinates,
                                )}
                                addressHint={data.destination_address}
                                municipalityHint={
                                    destinationCenter?.cityName ?? null
                                }
                                onConfirm={({ coords, address, placeName }) => {
                                    setData('destination_address', address);
                                    setData(
                                        'destination_coordinates',
                                        `${coords.lat.toFixed(7)},${coords.lng.toFixed(7)}`,
                                    );
                                    setData(
                                        'destination_coordinates_source',
                                        'manual',
                                    );
                                    setData(
                                        'destination_coordinates_accuracy',
                                        '',
                                    );
                                    setData('destination_place_id', '');
                                    if (
                                        !data.destination_municipality_id &&
                                        placeName
                                    ) {
                                        const target = normalizeCity(placeName);
                                        const match = municipalities.find(
                                            (m) =>
                                                normalizeCity(m.name) ===
                                                target,
                                        );
                                        if (match) {
                                            setData(
                                                'destination_municipality_id',
                                                String(match.id),
                                            );
                                            setDestinationPickerNoMatch(false);
                                        } else {
                                            setDestinationPickerNoMatch(true);
                                        }
                                    }
                                    setDestinationPickerOpen(false);
                                }}
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Horarios */}
            <Card>
                <CardHeader>
                    <CardTitle>Horarios</CardTitle>
                    {/* Render the schedule-timezone hint top-right of the
                        card header rather than under the input. Before this
                        sat in the same subgrid row as the field's
                        InputError, so when validation fired both overlapped
                        visually. CardAction is shadcn's native top-right
                        slot inside CardHeader. */}
                    <CardAction className="text-right text-xs text-muted-foreground">
                        <ScheduleTimezoneHint
                            date={serviceDate}
                            time={data.planned_start.slice(11, 16)}
                            timezone={resolvedTimezone}
                        />
                    </CardAction>
                </CardHeader>
                <CardContent>
                    <div className="grid gap-4 md:grid-cols-3 md:grid-rows-[auto_1fr_auto]">
                        <div
                            className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={invalid('planned_start')}
                        >
                            <Label htmlFor="planned_start">
                                Inicio Planificado *
                            </Label>
                            <ScheduleDateTimeField
                                value={data.planned_start}
                                onChange={handlePlannedStartChange}
                                min={plannedStartMinDate}
                                disabled={isFieldDisabled('planned_start')}
                                invalid={invalid('planned_start')}
                            />
                            <FieldFooter error={errors.planned_start} />
                        </div>
                        <div
                            className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={invalid('planned_duration')}
                        >
                            <Label htmlFor="planned_duration">
                                Duración Planificada (min) *
                            </Label>
                            <Input
                                id="planned_duration"
                                type="number"
                                value={data.planned_duration}
                                aria-invalid={invalid('planned_duration')}
                                disabled={isFieldDisabled('planned_duration')}
                                onChange={(e) =>
                                    handlePlannedDurationChange(e.target.value)
                                }
                                className="text-right tabular-nums"
                            />
                            <FieldFooter error={errors.planned_duration}>
                                {etaMinutes !== null && (
                                    <>
                                        ETA Google: {etaMinutes} min
                                        {etaMatchesCurrent ? (
                                            <> ✓</>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={applyEta}
                                                disabled={isFieldDisabled(
                                                    'planned_duration',
                                                )}
                                                className="ml-2 font-medium text-primary not-italic underline-offset-2 hover:underline disabled:opacity-50"
                                            >
                                                Aplicar
                                            </button>
                                        )}
                                    </>
                                )}
                            </FieldFooter>
                        </div>
                        <div
                            className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={invalid('planned_end')}
                        >
                            <Label htmlFor="planned_end">
                                Fin Planificado *
                            </Label>
                            <ScheduleDateTimeField
                                value={data.planned_end}
                                onChange={handlePlannedEndChange}
                                min={wallClockToDate(data.planned_start)}
                                disabled={isFieldDisabled('planned_duration')}
                                invalid={invalid('planned_end')}
                            />
                            <FieldFooter error={errors.planned_end}>
                                Derivado de Inicio + Duración
                            </FieldFooter>
                        </div>
                    </div>

                    {isClosed && (
                        <div className="grid gap-4 md:grid-cols-3 md:grid-rows-[auto_1fr_auto]">
                            <div
                                className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                                data-error={invalid('actual_start')}
                            >
                                <Label htmlFor="actual_start">
                                    Inicio Real *
                                </Label>
                                <ScheduleDateTimeField
                                    value={data.actual_start}
                                    onChange={(value) =>
                                        setData('actual_start', value)
                                    }
                                    min={actualLowerBound}
                                    max={actualUpperBound}
                                    disabled={isFieldDisabled('actual_start')}
                                    invalid={invalid('actual_start')}
                                />
                                <FieldFooter error={errors.actual_start} />
                            </div>
                            <div
                                className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                                data-error={invalid('actual_end')}
                            >
                                <Label htmlFor="actual_end">Fin Real *</Label>
                                <ScheduleDateTimeField
                                    value={data.actual_end}
                                    onChange={(value) =>
                                        setData('actual_end', value)
                                    }
                                    min={actualEndMin}
                                    max={actualUpperBound}
                                    disabled={isFieldDisabled('actual_end')}
                                    invalid={invalid('actual_end')}
                                />
                                <FieldFooter error={errors.actual_end} />
                            </div>
                            <div className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid">
                                <Label>Duración Real</Label>
                                <div className="flex items-center rounded-md border bg-muted/50 px-3 py-2 text-sm">
                                    {actualDuration ?? '—'}
                                </div>
                                <div />
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Facturación */}
            <Card>
                <CardHeader>
                    <CardTitle>Facturación</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-3 md:grid-rows-[auto_1fr_auto]">
                        <div
                            className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={invalid('billing_groups')}
                        >
                            <Label htmlFor="billing_groups">
                                Grupos de Facturación
                            </Label>
                            <BillingGroupsInput
                                id="billing_groups"
                                value={data.billing_groups}
                                onChange={(next) =>
                                    setData('billing_groups', next)
                                }
                                invalid={invalid('billing_groups')}
                                disabled={isFieldDisabled('billing_groups')}
                            />
                            <FieldFooter error={errors.billing_groups} />
                        </div>
                        <div
                            className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={invalid('unit_value')}
                        >
                            <Label htmlFor="unit_value">
                                Valor Unitario (COP) *
                            </Label>
                            <MoneyInput
                                id="unit_value"
                                name="unit_value"
                                value={data.unit_value}
                                onValueChange={(raw) =>
                                    setData('unit_value', raw)
                                }
                                invalid={invalid('unit_value')}
                                disabled={isFieldDisabled('unit_value')}
                                className="text-right tabular-nums"
                            />
                            <FieldFooter error={errors.unit_value} />
                        </div>
                        <div
                            className="group/field grid gap-2 md:row-span-3 md:grid-rows-subgrid"
                            data-error={invalid('quantity')}
                        >
                            <Label htmlFor="quantity">
                                {billingUnitLabel} *
                            </Label>
                            <Input
                                id="quantity"
                                type="number"
                                value={data.quantity}
                                aria-invalid={invalid('quantity')}
                                disabled={isFieldDisabled('quantity')}
                                onChange={(e) =>
                                    setData('quantity', e.target.value)
                                }
                                className="text-right tabular-nums"
                            />
                            <p className="text-xs text-muted-foreground">
                                {billingUnitHint}
                            </p>
                            <FieldFooter error={errors.quantity} />
                        </div>
                    </div>
                    <div
                        className="group/field grid gap-2"
                        data-error={invalid('payment_method')}
                    >
                        <Label htmlFor="payment_method">Método de Pago *</Label>
                        <Choicebox
                            id="payment_method"
                            value={data.payment_method}
                            onValueChange={(value) =>
                                setData('payment_method', value)
                            }
                            disabled={isFieldDisabled('payment_method')}
                            aria-invalid={invalid('payment_method')}
                            className="grid grid-cols-1 gap-2 sm:grid-cols-3"
                        >
                            {Object.entries(PaymentMethod).map(
                                ([key, value]) => {
                                    const Icon =
                                        PaymentMethodIcon[value] ?? null;
                                    return (
                                        <ChoiceboxItem
                                            key={key}
                                            id={`payment_method-${value}`}
                                            value={value}
                                        >
                                            <ChoiceboxItemHeader>
                                                <ChoiceboxItemTitle className="flex items-center gap-2">
                                                    {Icon && (
                                                        <Icon
                                                            aria-hidden
                                                            className="size-4"
                                                        />
                                                    )}
                                                    {PaymentMethodLabel[value]}
                                                </ChoiceboxItemTitle>
                                            </ChoiceboxItemHeader>
                                            <ChoiceboxIndicator />
                                        </ChoiceboxItem>
                                    );
                                },
                            )}
                        </Choicebox>
                        <FieldFooter error={errors.payment_method} />
                    </div>
                </CardContent>
            </Card>

            {requiresRetroactiveJustification && (
                <div
                    className="group/field grid gap-2"
                    data-error={invalid('manual_entry_justification')}
                >
                    <Label htmlFor="manual_entry_justification">
                        Justificación de registro retroactivo *
                    </Label>
                    <textarea
                        id="manual_entry_justification"
                        className="flex min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        value={data.manual_entry_justification}
                        placeholder="Ej: El servicio se ejecutó sin acceso al sistema; registro histórico."
                        minLength={10}
                        maxLength={500}
                        aria-invalid={invalid('manual_entry_justification')}
                        onChange={(e) =>
                            setData(
                                'manual_entry_justification',
                                e.target.value,
                            )
                        }
                    />
                    <FieldFooter error={errors.manual_entry_justification} />
                </div>
            )}

            {isAdminEdit && (
                <Card>
                    <CardHeader>
                        <CardTitle>Justificación del cambio</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            className="group/field grid gap-2"
                            data-error={invalid('justification')}
                        >
                            <Label htmlFor="justification">
                                Justificación del cambio *
                            </Label>
                            <textarea
                                id="justification"
                                className="flex min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                value={data.justification}
                                placeholder="Explique el motivo de la modificación..."
                                onChange={(e) =>
                                    setData('justification', e.target.value)
                                }
                            />
                            <FieldFooter error={errors.justification} />
                        </div>
                    </CardContent>
                </Card>
            )}
        </APIProvider>
    );
}
