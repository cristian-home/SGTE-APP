import { useMapsLibrary } from '@vis.gl/react-google-maps';
import {
    AlertTriangle,
    Loader2,
    LocateFixed,
    MapPin,
    MapPlus,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { dlog, dperf } from '@/lib/debug-log';
import {
    createSessionToken,
    fetchAutocomplete,
    locationTypeTone,
    type PlaceSuggestion,
    resolvePlace,
} from '@/lib/google-geocoding';
import { normalizeCity } from '@/lib/normalize-city';
import { cn } from '@/lib/utils';
import type { MunicipalityOption } from '@/components/municipality-combobox';

const MIN_QUERY_LENGTH = 3;
const TYPEAHEAD_DEBOUNCE_MS = 250;
/** Radius (m) of the location-bias circle around the selected municipality. */
const BIAS_RADIUS_M = 30_000;

export type CoordinatesSource = 'google' | 'manual' | 'centroid' | '';

interface LocationFieldProps {
    id: string;
    name: string;
    municipalities: MunicipalityOption[];
    municipalityId: string;
    address: string;
    coordinates: string;
    coordinatesSource: CoordinatesSource;
    coordinatesAccuracy: string;
    onMunicipalityChange: (id: string) => void;
    onAddressChange: (text: string) => void;
    onCoordinatesChange: (
        coords: string,
        source: 'google' | 'manual',
        accuracy: string | null,
        placeId: string | null,
    ) => void;
    onCommitInFlight: (inFlight: boolean) => void;
    onOpenMapPicker: () => void;
    pickerNoCityMatch?: boolean;
    disabled?: boolean;
    invalid?: boolean;
    invalidMunicipality?: boolean;
    invalidAddress?: boolean;
    placeholderCity?: string;
    placeholderAddress?: string;
    autoComplete?: string;
}

type Mode = 'city' | 'address';

type GroupedMunicipalities = {
    departmentName: string;
    items: MunicipalityOption[];
};

export default function LocationField({
    id,
    name,
    municipalities,
    municipalityId,
    address,
    coordinates,
    coordinatesSource,
    coordinatesAccuracy,
    onMunicipalityChange,
    onAddressChange,
    onCoordinatesChange,
    onCommitInFlight,
    onOpenMapPicker,
    pickerNoCityMatch,
    disabled,
    invalid,
    invalidMunicipality,
    invalidAddress,
    placeholderCity = 'Empieza por la ciudad…',
    placeholderAddress = 'Buscar dirección…',
    autoComplete = 'off',
}: LocationFieldProps) {
    const channel = `location-field:${id}`;
    const mode: Mode = municipalityId ? 'address' : 'city';

    // Load the Google Maps libraries this field needs. `places` powers
    // the autocomplete typeahead; `geocoding` backs resolvePlace's
    // Geocoder. Both come from the surrounding <APIProvider>.
    const placesLib = useMapsLibrary('places');
    useMapsLibrary('geocoding');
    const placesReady = placesLib !== null;

    // --- City mode -----------------------------------------------------
    // Separate from `address` so removing the chip doesn't accidentally
    // re-seed the dropdown with whatever the operator typed as an
    // address. Decision 3: text preserved, but city dropdown starts
    // empty until the operator types fresh into it.
    const [cityQuery, setCityQuery] = useState('');

    // --- Address mode --------------------------------------------------
    const [suggestions, setSuggestions] = useState<PlaceSuggestion[]>([]);
    const [loadingSuggest, setLoadingSuggest] = useState(false);
    const [committing, setCommitting] = useState(false);
    const [commitError, setCommitError] = useState<string | null>(null);

    // --- Shared --------------------------------------------------------
    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);

    const inputRef = useRef<HTMLInputElement | null>(null);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const listboxId = useId();

    const suggestAbortRef = useRef<AbortController | null>(null);
    const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    // One AutocompleteSessionToken groups a typeahead session's keystroke
    // requests; created on first keystroke, cleared after a commit.
    const sessionTokenRef =
        useRef<google.maps.places.AutocompleteSessionToken | null>(null);
    // Geocoder has no AbortController; a monotonic counter lets a newer
    // commit (or an unmount) supersede a slower in-flight resolvePlace.
    const commitSeqRef = useRef(0);

    // Bubble `committing` to the parent so it can disable the form's Save
    // button while a place-details fetch is in-flight (compliance D1).
    useEffect(() => {
        onCommitInFlight(committing);
    }, [committing, onCommitInFlight]);

    // Derived: the selected municipality option (memoized).
    const selectedMunicipality = useMemo(() => {
        if (!municipalityId) return null;
        const numId = Number(municipalityId);
        return municipalities.find((m) => m.id === numId) ?? null;
    }, [municipalities, municipalityId]);

    const municipalityLabel = selectedMunicipality
        ? selectedMunicipality.name
        : '';

    // Location bias for the Google autocomplete — a circle around the
    // selected municipality's centroid. Falls back to undefined (no
    // bias, Colombia-wide) when no city is selected.
    const locationBias = useMemo<
        google.maps.places.LocationBias | undefined
    >(() => {
        if (
            selectedMunicipality &&
            selectedMunicipality.latitude !== null &&
            selectedMunicipality.longitude !== null &&
            Number.isFinite(Number(selectedMunicipality.latitude)) &&
            Number.isFinite(Number(selectedMunicipality.longitude))
        ) {
            return {
                center: {
                    lat: Number(selectedMunicipality.latitude),
                    lng: Number(selectedMunicipality.longitude),
                },
                radius: BIAS_RADIUS_M,
            };
        }
        return undefined;
    }, [selectedMunicipality]);

    // --- City mode: local filter + group by department -----------------
    const grouped = useMemo<GroupedMunicipalities[]>(() => {
        const q = normalizeCity(cityQuery);
        const filtered = q
            ? municipalities.filter((m) => {
                  const byName = normalizeCity(m.name).includes(q);
                  const byCode = m.code.toLowerCase().includes(q);
                  return byName || byCode;
              })
            : municipalities;
        const map = new Map<string, GroupedMunicipalities>();
        for (const m of filtered) {
            const deptName = m.department?.name ?? 'Sin departamento';
            let g = map.get(deptName);
            if (!g) {
                g = { departmentName: deptName, items: [] };
                map.set(deptName, g);
            }
            g.items.push(m);
        }
        return Array.from(map.values());
    }, [municipalities, cityQuery]);

    // Flat list of options in the visible order — needed for keyboard
    // navigation (ArrowUp/Down) across the grouped dropdown.
    const flatCityOptions = useMemo<MunicipalityOption[]>(() => {
        return grouped.flatMap((g) => g.items);
    }, [grouped]);

    // --- Address mode: typeahead pipeline ------------------------------
    const cancelPendingSuggest = useCallback(() => {
        if (suggestAbortRef.current) {
            suggestAbortRef.current.abort();
            suggestAbortRef.current = null;
        }
        if (debounceTimerRef.current) {
            clearTimeout(debounceTimerRef.current);
            debounceTimerRef.current = null;
        }
    }, []);

    useEffect(() => {
        return () => {
            cancelPendingSuggest();
            // Supersede any in-flight commit so its resolve is ignored.
            commitSeqRef.current += 1;
        };
    }, [cancelPendingSuggest]);

    const runSuggest = useCallback(
        (text: string) => {
            const normalized = normalizeQuery(text);
            if (normalized.length < MIN_QUERY_LENGTH) {
                dlog(channel, 'suggest skip (below min length)', {
                    text,
                    normalized,
                });
                setSuggestions([]);
                setLoadingSuggest(false);
                return;
            }
            if (!placesReady) {
                dlog(channel, 'suggest skip (places library not loaded)');
                return;
            }
            cancelPendingSuggest();
            const ac = new AbortController();
            suggestAbortRef.current = ac;
            setLoadingSuggest(true);
            if (!sessionTokenRef.current) {
                sessionTokenRef.current = createSessionToken();
            }
            dlog(channel, 'suggest fire', {
                rawText: text,
                normalized,
                biased: locationBias !== undefined,
            });
            fetchAutocomplete(normalized, {
                sessionToken: sessionTokenRef.current,
                locationBias,
                signal: ac.signal,
            })
                .then((results) => {
                    if (ac.signal.aborted) return;
                    dlog(channel, 'suggest result', { kept: results.length });
                    setSuggestions(results);
                    setActiveIndex(-1);
                    setLoadingSuggest(false);
                })
                .catch((err) => {
                    if (ac.signal.aborted) return;
                    dlog(channel, 'suggest error', {
                        error: (err as Error).message,
                    });
                    setLoadingSuggest(false);
                });
        },
        [cancelPendingSuggest, channel, locationBias, placesReady],
    );

    const scheduleSuggest = useCallback(
        (text: string) => {
            cancelPendingSuggest();
            debounceTimerRef.current = setTimeout(
                () => runSuggest(text),
                TYPEAHEAD_DEBOUNCE_MS,
            );
        },
        [cancelPendingSuggest, runSuggest],
    );

    const commitPick = useCallback(
        async (suggestion: PlaceSuggestion) => {
            cancelPendingSuggest();

            const seq = ++commitSeqRef.current;
            setCommitting(true);
            setCommitError(null);
            const done = dperf(channel, 'commit', {
                placeId: suggestion.placeId,
                pickedText: suggestion.primaryText,
            });

            try {
                const resolved = await resolvePlace(suggestion.placeId);
                if (seq !== commitSeqRef.current) {
                    done({ outcome: 'superseded' });
                    return;
                }
                if (!resolved) {
                    done({ outcome: 'no-match' });
                    setCommitError(
                        'No se pudo confirmar la dirección. Intenta de nuevo o marca el punto en el mapa.',
                    );
                    return;
                }
                done({
                    outcome: 'resolved',
                    accuracy: resolved.locationType,
                });
                onAddressChange(resolved.formattedAddress);
                onCoordinatesChange(
                    `${resolved.lat.toFixed(7)},${resolved.lng.toFixed(7)}`,
                    'google',
                    resolved.locationType,
                    resolved.placeId,
                );
                // The place-details call closes this autocomplete session.
                sessionTokenRef.current = null;
                setOpen(false);
                setSuggestions([]);
                setActiveIndex(-1);
            } catch (err) {
                if (seq !== commitSeqRef.current) return;
                done({ outcome: 'error', error: (err as Error).message });
                setCommitError(
                    'No se pudo confirmar la dirección. Intenta de nuevo o marca el punto en el mapa.',
                );
            } finally {
                if (seq === commitSeqRef.current) {
                    setCommitting(false);
                }
            }
        },
        [cancelPendingSuggest, channel, onAddressChange, onCoordinatesChange],
    );

    // --- Handlers ------------------------------------------------------

    const handleCityPick = (option: MunicipalityOption) => {
        dlog(channel, 'city pick', { id: option.id, name: option.name });
        onMunicipalityChange(String(option.id));
        setCityQuery('');
        setOpen(false);
        setActiveIndex(-1);
        // Refocus so the operator can immediately type the address.
        // setTimeout to let React commit the chip first.
        setTimeout(() => inputRef.current?.focus(), 0);
        // If there was preserved address text from a previous city, it
        // re-activates as the Google query immediately.
        if (address.trim().length >= MIN_QUERY_LENGTH) {
            scheduleSuggest(address);
        }
    };

    const handleChipRemove = useCallback(() => {
        dlog(channel, 'chip remove');
        cancelPendingSuggest();
        onMunicipalityChange('');
        // Discard coords — they belonged to the previous city.
        if (coordinates) {
            onCoordinatesChange('', 'manual', null, null);
        }
        setSuggestions([]);
        setActiveIndex(-1);
        setCommitError(null);
        // Don't seed cityQuery from `address` (decision 3): operator
        // gets a fresh empty city dropdown.
        setCityQuery('');
        setOpen(false);
    }, [
        cancelPendingSuggest,
        channel,
        coordinates,
        onCoordinatesChange,
        onMunicipalityChange,
    ]);

    const handleChipClickBody = useCallback(() => {
        dlog(channel, 'chip click body');
        // Re-open the combobox to change the city. Discards coords (they
        // belonged to the previous city) but preserves the address text
        // (the operator may just want to move it to another city).
        cancelPendingSuggest();
        onMunicipalityChange('');
        if (coordinates) {
            onCoordinatesChange('', 'manual', null, null);
        }
        setCityQuery('');
        setSuggestions([]);
        setActiveIndex(-1);
        setOpen(true);
        setTimeout(() => inputRef.current?.focus(), 0);
    }, [
        cancelPendingSuggest,
        channel,
        coordinates,
        onCoordinatesChange,
        onMunicipalityChange,
    ]);

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const next = e.target.value;
        if (mode === 'city') {
            setCityQuery(next);
            setOpen(true);
            setActiveIndex(-1);
            return;
        }
        // mode === 'address'
        dlog(channel, 'address input change', { value: next });
        onAddressChange(next);
        setCommitError(null);
        setOpen(true);
        scheduleSuggest(next);
    };

    const handleInputFocus = () => {
        if (mode === 'city') {
            setOpen(true);
            return;
        }
        if (suggestions.length > 0) setOpen(true);
    };

    const handleClearText = useCallback(() => {
        dlog(channel, 'clear text+coords by X');
        cancelPendingSuggest();
        onAddressChange('');
        if (coordinates) {
            onCoordinatesChange('', 'manual', null, null);
        }
        setSuggestions([]);
        setActiveIndex(-1);
        setOpen(false);
        setCommitError(null);
    }, [
        cancelPendingSuggest,
        channel,
        coordinates,
        onAddressChange,
        onCoordinatesChange,
    ]);

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        // Backspace at cursor=0 with empty input removes the chip.
        if (
            e.key === 'Backspace' &&
            mode === 'address' &&
            (e.currentTarget.value ?? '') === '' &&
            e.currentTarget.selectionStart === 0 &&
            e.currentTarget.selectionEnd === 0
        ) {
            e.preventDefault();
            handleChipRemove();
            return;
        }

        if (mode === 'city') {
            if (!open) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    setOpen(true);
                }
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActiveIndex(
                    (i) => (i + 1) % Math.max(flatCityOptions.length, 1),
                );
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActiveIndex((i) =>
                    i <= 0 ? flatCityOptions.length - 1 : i - 1,
                );
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && activeIndex < flatCityOptions.length) {
                    e.preventDefault();
                    handleCityPick(flatCityOptions[activeIndex]);
                }
            } else if (e.key === 'Escape') {
                setOpen(false);
            }
            return;
        }

        // mode === 'address'
        if (!open || suggestions.length === 0) {
            if (
                e.key === 'ArrowDown' &&
                (e.currentTarget.value ?? '').trim().length >= MIN_QUERY_LENGTH
            ) {
                setOpen(true);
                runSuggest(e.currentTarget.value);
                e.preventDefault();
            }
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex((i) => (i + 1) % suggestions.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex((i) => (i <= 0 ? suggestions.length - 1 : i - 1));
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && activeIndex < suggestions.length) {
                e.preventDefault();
                void commitPick(suggestions[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    };

    // Click-outside closes the dropdown.
    useEffect(() => {
        if (!open) return;
        const onClickOutside = (e: MouseEvent) => {
            if (
                containerRef.current &&
                !containerRef.current.contains(e.target as Node)
            ) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onClickOutside);
        return () => document.removeEventListener('mousedown', onClickOutside);
    }, [open]);

    const inputValue = mode === 'city' ? cityQuery : address;
    const placeholder = mode === 'city' ? placeholderCity : placeholderAddress;

    const addressHasText = mode === 'address' && address.trim().length > 0;
    const needsConfirmation = addressHasText && !coordinates;
    const showClearButton =
        !disabled &&
        !committing &&
        ((mode === 'address' && (addressHasText || coordinates !== '')) ||
            (mode === 'city' && cityQuery.length > 0));

    const showCityDropdown = mode === 'city' && open && !disabled;
    const showAddressDropdown =
        mode === 'address' &&
        open &&
        !disabled &&
        (loadingSuggest || suggestions.length > 0) &&
        normalizeQuery(address).length >= MIN_QUERY_LENGTH;

    const showDropdown = showCityDropdown || showAddressDropdown;
    const wrapperInvalid =
        invalid || invalidMunicipality || invalidAddress || false;

    return (
        <div ref={containerRef} className="min-w-0 space-y-1">
            <ButtonGroup className="w-full">
                {/* Inner shell that holds prefix icon + chip + native input
                    + right-side icons. Carries the visual border so the
                    chip and input read as a single control. The shell's
                    right edge stays flat to seam against the map button. */}
                <div
                    className={cn(
                        'relative flex min-w-0 flex-1 items-center gap-1 rounded-l-md border bg-background px-2 py-1 text-sm',
                        'focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/20',
                        wrapperInvalid &&
                            'border-destructive focus-within:ring-destructive/20',
                        disabled && 'cursor-not-allowed opacity-50',
                    )}
                >
                    <MapPin className="size-4 shrink-0 text-muted-foreground" />
                    {selectedMunicipality && (
                        <CityChip
                            label={municipalityLabel}
                            onRemove={handleChipRemove}
                            onClickBody={handleChipClickBody}
                            disabled={disabled || committing}
                        />
                    )}
                    <input
                        ref={inputRef}
                        id={id}
                        name={name}
                        type="text"
                        role="combobox"
                        aria-expanded={showDropdown}
                        aria-controls={listboxId}
                        aria-autocomplete="list"
                        aria-activedescendant={
                            activeIndex >= 0
                                ? `${listboxId}-option-${activeIndex}`
                                : undefined
                        }
                        aria-invalid={wrapperInvalid}
                        autoComplete={autoComplete}
                        data-1p-ignore="true"
                        data-lpignore="true"
                        spellCheck={false}
                        value={inputValue}
                        onChange={handleInputChange}
                        onKeyDown={handleKeyDown}
                        onFocus={handleInputFocus}
                        placeholder={placeholder}
                        disabled={disabled || committing}
                        className="min-w-0 flex-1 truncate bg-transparent text-sm outline-none placeholder:text-muted-foreground focus:text-clip disabled:cursor-not-allowed"
                    />
                    {(loadingSuggest || committing) && (
                        <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" />
                    )}
                    {showClearButton && (
                        <button
                            type="button"
                            onClick={handleClearText}
                            aria-label="Limpiar dirección"
                            title="Limpiar dirección"
                            className="inline-flex size-5 shrink-0 items-center justify-center rounded-sm text-muted-foreground hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <X className="size-3.5" />
                        </button>
                    )}
                    {coordinates && !committing && (
                        <CoordsIndicator
                            coordinates={coordinates}
                            source={coordinatesSource}
                            accuracy={coordinatesAccuracy}
                        />
                    )}
                    {showDropdown && (
                        <div
                            className={cn(
                                'absolute inset-x-0 top-full z-50 mt-1 max-h-72 overflow-auto',
                                'rounded-md border bg-popover shadow-md',
                            )}
                        >
                            {mode === 'city' ? (
                                <CityDropdown
                                    listboxId={listboxId}
                                    grouped={grouped}
                                    flat={flatCityOptions}
                                    activeIndex={activeIndex}
                                    selectedId={
                                        selectedMunicipality?.id ?? null
                                    }
                                    onSelect={handleCityPick}
                                    setActiveIndex={setActiveIndex}
                                />
                            ) : (
                                <AddressDropdown
                                    listboxId={listboxId}
                                    suggestions={suggestions}
                                    loadingSuggest={loadingSuggest}
                                    activeIndex={activeIndex}
                                    onSelect={(s) => void commitPick(s)}
                                    setActiveIndex={setActiveIndex}
                                />
                            )}
                        </div>
                    )}
                </div>
                <TooltipProvider delayDuration={300}>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    dlog(channel, 'map picker open requested');
                                    cancelPendingSuggest();
                                    setOpen(false);
                                    onOpenMapPicker();
                                }}
                                disabled={disabled || committing}
                                aria-label="Marcar en mapa"
                                className="shrink-0"
                            >
                                <MapPlus className="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Marcar en mapa</TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </ButtonGroup>

            {commitError && (
                <p className="flex items-start gap-1 text-xs text-red-600 dark:text-red-400">
                    <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                    <span>{commitError}</span>
                </p>
            )}

            {needsConfirmation && !commitError && !committing && (
                <p className="flex items-start gap-1 text-xs text-amber-700 dark:text-amber-400">
                    <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                    <span>
                        Selecciona una sugerencia o marca el punto en el mapa
                        para confirmar la ubicación.
                    </span>
                </p>
            )}

            {pickerNoCityMatch && (
                <p className="flex items-start gap-1 text-xs text-amber-700 dark:text-amber-400">
                    <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                    <span>
                        No reconocí la ciudad del pin, selecciónala manualmente.
                    </span>
                </p>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------
// Subcomponents
// ---------------------------------------------------------------------

function CityChip({
    label,
    onRemove,
    onClickBody,
    disabled,
}: {
    label: string;
    onRemove: () => void;
    onClickBody: () => void;
    disabled?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-md bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground capitalize shadow-sm',
                disabled && 'pointer-events-none opacity-60',
            )}
        >
            <button
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    onClickBody();
                }}
                aria-label={`Cambiar ciudad seleccionada: ${label}`}
                disabled={disabled}
                className="cursor-pointer rounded-sm capitalize focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed"
            >
                {label}
            </button>
            <button
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    onRemove();
                }}
                aria-label={`Quitar ciudad ${label}`}
                disabled={disabled}
                className="inline-flex size-4 items-center justify-center rounded-sm text-primary-foreground/70 hover:bg-primary-foreground/20 hover:text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/50 focus-visible:outline-none disabled:cursor-not-allowed"
            >
                <X className="size-3" />
            </button>
        </span>
    );
}

function CityDropdown({
    listboxId,
    grouped,
    flat,
    activeIndex,
    selectedId,
    onSelect,
    setActiveIndex,
}: {
    listboxId: string;
    grouped: GroupedMunicipalities[];
    flat: MunicipalityOption[];
    activeIndex: number;
    selectedId: number | null;
    onSelect: (option: MunicipalityOption) => void;
    setActiveIndex: (i: number) => void;
}) {
    if (grouped.length === 0) {
        return (
            <ul id={listboxId} role="listbox" className="py-1 text-sm">
                <li className="px-3 py-2 text-muted-foreground">
                    No se encontró ciudad.
                </li>
            </ul>
        );
    }

    return (
        <ul id={listboxId} role="listbox" className="py-1 text-sm">
            {grouped.map((group) => (
                <li
                    key={group.departmentName}
                    role="group"
                    aria-label={group.departmentName}
                >
                    <div className="sticky top-0 bg-popover px-3 py-1 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                        {group.departmentName}
                    </div>
                    {group.items.map((m) => {
                        const i = flat.findIndex((x) => x.id === m.id);
                        const active = i === activeIndex;
                        const selected = m.id === selectedId;
                        return (
                            <button
                                key={m.id}
                                type="button"
                                id={`${listboxId}-option-${i}`}
                                role="option"
                                aria-selected={active}
                                className={cn(
                                    'flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-left',
                                    active
                                        ? 'bg-accent text-accent-foreground'
                                        : 'hover:bg-accent/60',
                                )}
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    onSelect(m);
                                }}
                                onMouseEnter={() => setActiveIndex(i)}
                            >
                                <span
                                    className={cn(
                                        'size-1.5 shrink-0 rounded-full',
                                        selected
                                            ? 'bg-primary'
                                            : 'bg-transparent',
                                    )}
                                    aria-hidden
                                />
                                <span className="flex-1 truncate capitalize">
                                    {m.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {m.code}
                                </span>
                            </button>
                        );
                    })}
                </li>
            ))}
        </ul>
    );
}

function AddressDropdown({
    listboxId,
    suggestions,
    loadingSuggest,
    activeIndex,
    onSelect,
    setActiveIndex,
}: {
    listboxId: string;
    suggestions: PlaceSuggestion[];
    loadingSuggest: boolean;
    activeIndex: number;
    onSelect: (s: PlaceSuggestion) => void;
    setActiveIndex: (i: number) => void;
}) {
    return (
        <>
            <ul id={listboxId} role="listbox" className="py-1 text-sm">
                {loadingSuggest && suggestions.length === 0 && (
                    <li className="flex items-center gap-2 px-3 py-2 text-muted-foreground">
                        <Loader2 className="size-3 animate-spin" />
                        Buscando direcciones…
                    </li>
                )}
                {suggestions.map((s, i) => (
                    <li
                        key={s.placeId}
                        id={`${listboxId}-option-${i}`}
                        role="option"
                        aria-selected={i === activeIndex}
                        className={cn(
                            'flex cursor-pointer items-start gap-2 px-3 py-2',
                            i === activeIndex
                                ? 'bg-accent text-accent-foreground'
                                : 'hover:bg-accent/60',
                        )}
                        onMouseDown={(e) => {
                            e.preventDefault();
                            onSelect(s);
                        }}
                        onMouseEnter={() => setActiveIndex(i)}
                    >
                        <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div className="flex min-w-0 flex-col">
                            <span className="truncate font-medium">
                                {s.primaryText}
                            </span>
                            {s.secondaryText && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {s.secondaryText}
                                </span>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            {/* Places API policy requires Google attribution wherever
                predictions are shown outside a Google map. */}
            <div className="border-t px-3 py-1.5 text-right text-[10px] text-muted-foreground">
                Con tecnología de{' '}
                <span className="font-semibold tracking-tight">
                    <span className="text-[#4285F4]">G</span>
                    <span className="text-[#EA4335]">o</span>
                    <span className="text-[#FBBC05]">o</span>
                    <span className="text-[#4285F4]">g</span>
                    <span className="text-[#34A853]">l</span>
                    <span className="text-[#EA4335]">e</span>
                </span>
            </div>
        </>
    );
}

/**
 * Inline coords indicator. Lives inside the input shell, right after the
 * X clear button, so confirmed coordinates communicate themselves
 * without adding vertical height under the control (which would
 * desalign the Origen/Destino grid when only one side has coords).
 *
 * The icon color encodes confidence (`badgeTone`); the tooltip carries
 * the full text "Coordenadas: lat,lng · source · accuracy" for anyone
 * who hovers or focuses it.
 */
function CoordsIndicator({
    coordinates,
    source,
    accuracy,
}: {
    coordinates: string;
    source: CoordinatesSource;
    accuracy: string;
}) {
    const tone = badgeTone(source, accuracy);
    const toneClass =
        tone === 'green'
            ? 'text-emerald-600 dark:text-emerald-400'
            : tone === 'yellow'
              ? 'text-amber-600 dark:text-amber-400'
              : 'text-muted-foreground';
    const sourceText = sourceLabel(source);
    const detail = [
        sourceText || null,
        accuracy && source === 'google' ? accuracy : null,
    ]
        .filter(Boolean)
        .join(' · ');
    const ariaLabel = `Ubicación confirmada: ${coordinates}${detail ? `, ${detail}` : ''}`;
    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span
                        role="img"
                        aria-label={ariaLabel}
                        className={cn(
                            'inline-flex size-5 shrink-0 items-center justify-center',
                            toneClass,
                        )}
                    >
                        <LocateFixed className="size-4" />
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    <div className="text-xs">
                        <div>
                            Coordenadas: <code>{coordinates}</code>
                        </div>
                        {detail && (
                            <div className="text-muted-foreground">
                                {detail}
                            </div>
                        )}
                    </div>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

function sourceLabel(source: CoordinatesSource): string {
    if (source === 'google') return 'Google';
    if (source === 'manual') return 'pin manual';
    if (source === 'centroid') return 'centro de la ciudad';
    return '';
}

function badgeTone(
    source: CoordinatesSource,
    accuracy: string,
): 'green' | 'yellow' | 'gray' {
    if (source === 'google') {
        return locationTypeTone(accuracy);
    }
    return 'gray';
}

function normalizeQuery(input: string): string {
    return input.replace(/[#-]/g, ' ').replace(/\s+/g, ' ').trim();
}
