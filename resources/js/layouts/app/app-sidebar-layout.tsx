import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ErrorBoundary } from '@/components/error-boundary';
import { useViewerTimezone } from '@/hooks/use-viewer-timezone';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    useViewerTimezone();
    // Reset the boundary on every SPA navigation so a crash on one page
    // never sticks once the user moves elsewhere. The sidebar/header live
    // outside the boundary, so navigation always stays available.
    const { url } = usePage();

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className="flex-1 [view-transition-name:main-content]">
                    <ErrorBoundary resetKeys={[url]}>{children}</ErrorBoundary>
                </div>
            </AppContent>
        </AppShell>
    );
}
