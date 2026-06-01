import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Column } from '@tanstack/react-table';

interface DataTableColumnHeaderProps<
    TData,
    TValue,
> extends React.ComponentProps<'div'> {
    column: Column<TData, TValue>;
    title: string;
}

export function DataTableColumnHeader<TData, TValue>({
    column,
    title,
    className,
}: DataTableColumnHeaderProps<TData, TValue>) {
    'use no memo';

    if (!column.getCanSort()) {
        return <div className={cn(className)}>{title}</div>;
    }

    const sorted = column.getIsSorted();

    // Cycle through three states so the sort can be removed: unsorted → asc →
    // desc → unsorted (back to the table's default order). Clicking only ever
    // flipping asc⇄desc would trap the user in a sorted state.
    const cycleSort = () => {
        if (sorted === false) {
            column.toggleSorting(false);
        } else if (sorted === 'asc') {
            column.toggleSorting(true);
        } else {
            column.clearSorting();
        }
    };

    return (
        <Button
            variant="ghost"
            size="sm"
            className={cn('h-8', className)}
            onClick={cycleSort}
            title={
                sorted === 'asc'
                    ? 'Ordenado ascendente — clic para descendente'
                    : sorted === 'desc'
                      ? 'Ordenado descendente — clic para quitar el orden'
                      : 'Sin orden — clic para ordenar'
            }
        >
            {title}
            {sorted === 'desc' ? (
                <ArrowDown className="ml-2 size-4" />
            ) : sorted === 'asc' ? (
                <ArrowUp className="ml-2 size-4" />
            ) : (
                <ArrowUpDown className="ml-2 size-4" />
            )}
        </Button>
    );
}
