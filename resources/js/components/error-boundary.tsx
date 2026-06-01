import { AlertTriangle } from 'lucide-react';
import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';

interface FallbackArgs {
    error: Error;
    reset: () => void;
}

interface ErrorBoundaryProps {
    children: ReactNode;
    /**
     * UI to render when a descendant throws. Either a static node or a
     * render callback that receives the caught error and a `reset` fn to
     * clear the boundary and re-attempt rendering the children.
     */
    fallback?: ReactNode | ((args: FallbackArgs) => ReactNode);
    /**
     * When any value in this array changes while the boundary is in the
     * error state, the boundary resets automatically. Pass the current
     * Inertia `url` here so SPA navigation always clears a stale error.
     */
    resetKeys?: ReadonlyArray<unknown>;
    onError?: (error: Error, info: ErrorInfo) => void;
}

interface ErrorBoundaryState {
    error: Error | null;
}

function resetKeysChanged(
    prev: ReadonlyArray<unknown> = [],
    next: ReadonlyArray<unknown> = [],
): boolean {
    if (prev.length !== next.length) {
        return true;
    }
    return prev.some((value, index) => !Object.is(value, next[index]));
}

/**
 * Catches render/commit/effect errors thrown by descendants so a single
 * failing subtree (e.g. a Google Maps widget when the API key is
 * revoked) degrades to a fallback instead of unmounting the whole React
 * tree. Without this, an error thrown during Inertia's `swapComponent`
 * commit blanks the page and truncates SPA navigation.
 */
export class ErrorBoundary extends Component<
    ErrorBoundaryProps,
    ErrorBoundaryState
> {
    state: ErrorBoundaryState = { error: null };

    static getDerivedStateFromError(error: Error): ErrorBoundaryState {
        return { error };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        this.props.onError?.(error, info);
    }

    componentDidUpdate(prevProps: ErrorBoundaryProps): void {
        if (
            this.state.error !== null &&
            resetKeysChanged(prevProps.resetKeys, this.props.resetKeys)
        ) {
            this.reset();
        }
    }

    reset = (): void => {
        this.setState({ error: null });
    };

    render(): ReactNode {
        const { error } = this.state;
        if (error !== null) {
            const { fallback } = this.props;
            if (typeof fallback === 'function') {
                return fallback({ error, reset: this.reset });
            }
            if (fallback !== undefined) {
                return fallback;
            }
            return <DefaultErrorFallback reset={this.reset} />;
        }
        return this.props.children;
    }
}

function DefaultErrorFallback({ reset }: { reset: () => void }): ReactNode {
    return (
        <div className="flex min-h-40 flex-col items-center justify-center gap-3 p-6 text-center">
            <AlertTriangle
                className="size-6 text-muted-foreground"
                aria-hidden
            />
            <div className="space-y-1">
                <p className="text-sm font-medium">
                    Algo salió mal al mostrar esta sección.
                </p>
                <p className="text-sm text-muted-foreground">
                    El resto de la aplicación sigue funcionando. Puedes
                    reintentar o navegar a otra página.
                </p>
            </div>
            <Button type="button" variant="outline" size="sm" onClick={reset}>
                Reintentar
            </Button>
        </div>
    );
}
