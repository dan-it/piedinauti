import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';
import { avviaSincronizzazione, pulisciDatiLocali, rete } from './lib/rete';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// Sends the taps still waiting on the phone (made without signal) now, and whenever signal may be back.
avviaSincronizzazione();

// The service worker keeps the app and today's screens on the phone so they open without signal.
// It needs HTTPS (or localhost) and is used only by the built app, not by the Vite dev server.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    });
}

// Signing out removes everything kept on the phone. If taps are still waiting to be sent they
// would be lost, so ask first.
router.on('before', (evento) => {
    const visita = evento.detail.visit;

    if (visita.method !== 'post' || new URL(String(visita.url), window.location.origin).pathname !== '/logout') {
        return;
    }

    if (rete.inAttesa > 0 && !window.confirm(`Ci sono ${rete.inAttesa} presenze non ancora inviate: se esci le perdi. Vuoi uscire comunque?`)) {
        return false;
    }

    void pulisciDatiLocali();
});
