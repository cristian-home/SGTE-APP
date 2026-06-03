import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';
import { initializeTheme } from './hooks/use-appearance';

configureEcho({
    broadcaster: 'reverb',
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent<ResolvedComponent>(
            `./pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>('./pages/**/*.tsx', {
                import: 'default',
            }),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <App {...props} />
            </StrictMode>,
        );
    },
    defaults: {
        visitOptions: () => {
            // Record current layout before navigation so CSS can
            // distinguish same-layout vs cross-layout transitions.
            if (document.querySelector('[data-slot="sidebar"]')) {
                document.documentElement.setAttribute('data-had-sidebar', '');
            } else {
                document.documentElement.removeAttribute('data-had-sidebar');
            }
            return { viewTransition: true };
        },
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// PWA service worker registration. Only runs in production builds — the dev
// server never ships a SW (see `devOptions.enabled` in vite.config.ts).
if (import.meta.env.PROD) {
    // The `virtual:pwa-register` module is provided at build time by
    // `vite-plugin-pwa`. We import it lazily so the dev bundle never tries
    // to resolve it.
    import('virtual:pwa-register')
        .then(({ registerSW }) => {
            registerSW({
                immediate: true,
                onNeedRefresh() {
                    // TODO(pwa-phase-2): replace this console log with an
                    // in-app toast that offers the user a "Recargar" action,
                    // calling `updateSW(true)` to activate the new SW.
                    // For now we just surface that an update is available
                    // so developers can confirm the SW lifecycle works.

                    console.info(
                        '[PWA] New content available, refresh to update.',
                    );
                },
                onOfflineReady() {
                    console.info('[PWA] App shell cached for offline use.');
                },
            });
        })
        .catch((error) => {
            console.warn('[PWA] Failed to register service worker', error);
        });
}
