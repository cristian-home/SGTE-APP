import { Check, PlusCircle } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';

import type { FilterOption } from '@/types';

interface DataTableFacetedFilterProps {
    name: string;
    label: string;
    options: FilterOption[];
    selected: string[];
    onSelectionChange: (values: string[]) => void;
    capitalizeOptions?: boolean;
    /** Split into "with records" + a collapsible "Otras (N)" section. */
    sectioned?: boolean;
}

export function DataTableFacetedFilter({
    label,
    options,
    selected,
    onSelectionChange,
    capitalizeOptions,
    sectioned,
}: DataTableFacetedFilterProps) {
    'use no memo';
    const [search, setSearch] = useState('');
    const [showOthers, setShowOthers] = useState(false);
    const selectedSet = new Set(selected);

    function toggleValue(value: string) {
        const next = new Set(selectedSet);
        if (next.has(value)) {
            next.delete(value);
        } else {
            next.add(value);
        }
        onSelectionChange(Array.from(next));
    }

    // Partition only for sectioned facets: values with records on top
    // (most relevant first), the rest (count 0) deferred to "Otras".
    const { withRecords, others } = useMemo(() => {
        if (!sectioned) {
            return { withRecords: options, others: [] as FilterOption[] };
        }
        const wr = options
            .filter((o) => (o.count ?? 0) > 0)
            .sort((a, b) => (b.count ?? 0) - (a.count ?? 0));
        const ot = options
            .filter((o) => (o.count ?? 0) === 0)
            .sort((a, b) => a.label.localeCompare(b.label));
        return { withRecords: wr, others: ot };
    }, [options, sectioned]);

    // Mount the "others" items when expanded OR while searching, so the
    // filter's own search can still reach every value without paying the
    // cost of rendering them all by default.
    const showOthersGroup = showOthers || search.trim() !== '';

    const renderItem = (option: FilterOption, greyed = false) => {
        const isSelected = selectedSet.has(option.value);
        return (
            <CommandItem
                key={option.value}
                value={option.label}
                onSelect={() => toggleValue(option.value)}
            >
                <div
                    className={cn(
                        'mr-2 flex size-4 items-center justify-center rounded-sm border border-primary',
                        isSelected
                            ? 'bg-primary text-primary-foreground'
                            : 'opacity-50 [&_svg]:invisible',
                    )}
                >
                    <Check className="size-4" />
                </div>
                {option.icon && (
                    <option.icon className="mr-2 size-4 text-muted-foreground" />
                )}
                <span
                    className={cn(
                        'truncate',
                        capitalizeOptions && 'capitalize',
                        greyed && 'text-muted-foreground',
                    )}
                >
                    {option.label}
                </span>
                {option.count !== undefined && !greyed && (
                    <span className="ml-auto pl-2 text-xs text-muted-foreground tabular-nums">
                        {option.count}
                    </span>
                )}
            </CommandItem>
        );
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-8 border-dashed"
                >
                    <PlusCircle className="mr-2 size-4" />
                    {label}
                    {selectedSet.size > 0 && (
                        <>
                            <Separator
                                orientation="vertical"
                                className="mx-2 h-4"
                            />
                            <Badge
                                variant="secondary"
                                className="rounded-sm px-1 font-normal"
                            >
                                {selectedSet.size}
                            </Badge>
                        </>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-56 p-0" align="start">
                <Command>
                    <CommandInput
                        placeholder={label}
                        value={search}
                        onValueChange={setSearch}
                    />
                    <CommandList>
                        <CommandEmpty>Sin resultados.</CommandEmpty>
                        <CommandGroup>
                            {withRecords.map((option) => renderItem(option))}
                        </CommandGroup>
                        {sectioned && others.length > 0 && (
                            <>
                                <CommandSeparator />
                                {showOthersGroup ? (
                                    <CommandGroup
                                        heading={`Otras (${others.length})`}
                                    >
                                        {others.map((option) =>
                                            renderItem(option, true),
                                        )}
                                    </CommandGroup>
                                ) : (
                                    <CommandGroup>
                                        <CommandItem
                                            value="__show_others__"
                                            onSelect={() => setShowOthers(true)}
                                            className="text-muted-foreground"
                                        >
                                            Otras ({others.length})
                                        </CommandItem>
                                    </CommandGroup>
                                )}
                            </>
                        )}
                        {selectedSet.size > 0 && (
                            <>
                                <CommandSeparator />
                                <CommandGroup>
                                    <CommandItem
                                        onSelect={() => onSelectionChange([])}
                                        className="justify-center text-center"
                                    >
                                        Limpiar filtros
                                    </CommandItem>
                                </CommandGroup>
                            </>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
