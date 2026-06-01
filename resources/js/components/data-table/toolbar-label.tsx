import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

/**
 * Hides its text below the `@md` container breakpoint (~448px) so the
 * surrounding button collapses to icon-only. The DataTable toolbar
 * marks its top bar as `@container/toolbar`; size queries here resolve
 * against that container, not the viewport — so the buttons collapse
 * when the toolbar is narrow (e.g. sidebar expanded on a medium
 * window), not just on a small screen.
 *
 * Margin lives on the label (`@md:ml-2`), not on the icon, so the icon
 * stays centered inside the square button when the label hides.
 */
export function ToolbarLabel({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <span className={cn('hidden @lg:inline', className)}>{children}</span>
    );
}
