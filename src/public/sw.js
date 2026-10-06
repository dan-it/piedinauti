// Service worker of Piedinauti.
//
// It keeps the app's files and the chaperone's screens ("Oggi") on the phone, so the morning screen
// opens even without signal. The taps themselves are not handled here: the page keeps them in its
// own outbox (resources/js/lib/coda.ts) and sends them when there is signal.
//
// What is kept:
// - the built files (/build/assets/*), icons and manifest: served from the phone first;
// - the pages of the chaperone's screen: the network first (so the data is current), the saved copy
//   when there is no signal or the connection is too slow.
// Nothing else is touched, and nothing but GET requests: signing in, saving and everything else
// always go to the server.

const VERSIONE = 'v1';
const CACHE_FILE = 'piedinauti-file-' + VERSIONE;
const CACHE_PAGINE = 'piedinauti-pagine-' + VERSIONE;
const ATTESA_RETE_MS = 4000;

const eFile = (percorso) =>
    percorso.startsWith('/build/assets/') || percorso.startsWith('/icons/') || percorso === '/manifest.webmanifest' || percorso === '/favicon.ico';

// The chaperone's screens worth keeping: the entry point and each line.
const eSchermataOggi = (percorso) => percorso === '/oggi' || percorso.startsWith('/oggi/linee/');

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        (async () => {
            // Remove the copies of older versions.
            const nomi = await caches.keys();
            await Promise.all(nomi.filter((nome) => nome.startsWith('piedinauti-') && nome !== CACHE_FILE && nome !== CACHE_PAGINE).map((nome) => caches.delete(nome)));
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('fetch', (evento) => {
    const richiesta = evento.request;

    if (richiesta.method !== 'GET') {
        return;
    }

    const url = new URL(richiesta.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (eFile(url.pathname)) {
        evento.respondWith(primaDalTelefono(richiesta));

        return;
    }

    if (!eSchermataOggi(url.pathname)) {
        return;
    }

    if (richiesta.mode === 'navigate') {
        evento.respondWith(paginaConCopia(evento, url));

        return;
    }

    // A visit made by the app itself (Inertia). Partial reloads are never kept: they hold only some of
    // the data and must not stand in for the whole page.
    if (richiesta.headers.get('X-Inertia') && !richiesta.headers.get('X-Inertia-Partial-Data')) {
        evento.respondWith(visitaConCopia(evento));
    }
});

// Rejects if the promise has not settled within the given time.
function entroIlTempo(promessa, millisecondi) {
    return new Promise((risolvi, rifiuta) => {
        const timer = setTimeout(() => rifiuta(new Error('troppo lenta')), millisecondi);

        promessa.then(
            (valore) => {
                clearTimeout(timer);
                risolvi(valore);
            },
            (errore) => {
                clearTimeout(timer);
                rifiuta(errore);
            },
        );
    });
}

// A page that arrived through a redirect cannot be given back to the browser as it is: copy it.
function senzaRedirect(risposta) {
    if (!risposta.redirected) {
        return risposta;
    }

    return new Response(risposta.body, { status: risposta.status, statusText: risposta.statusText, headers: risposta.headers });
}

async function primaDalTelefono(richiesta) {
    const cache = await caches.open(CACHE_FILE);
    const salvata = await cache.match(richiesta);

    if (salvata) {
        return salvata;
    }

    const risposta = await fetch(richiesta);

    if (risposta.ok) {
        await cache.put(richiesta, risposta.clone());
    }

    return risposta;
}

// Opening a screen: the network first; the saved copy if there is no signal or it is too slow.
async function paginaConCopia(evento, url) {
    const cache = await caches.open(CACHE_PAGINE);
    // The copy is kept under the path asked for: "/oggi" keeps the line it led to.
    const chiave = url.pathname;

    const daRete = fetch(url.href, {
        credentials: 'same-origin',
        redirect: 'follow',
        headers: { Accept: 'text/html,application/xhtml+xml' },
    }).then(async (risposta) => {
        // Keep it only if it really is one of the screens (not, say, the sign-in page after a redirect).
        if (risposta.ok && eSchermataOggi(new URL(risposta.url).pathname)) {
            await cache.put(chiave, senzaRedirect(risposta.clone()));
        }

        return risposta;
    });

    try {
        return senzaRedirect(await entroIlTempo(daRete, ATTESA_RETE_MS));
    } catch {
        const salvata = await cache.match(chiave);

        if (salvata) {
            // Let the slow request finish in the background to refresh the copy.
            evento.waitUntil(daRete.catch(() => undefined));

            return salvata;
        }

        // No copy yet: wait for the network, however slow. Fails if there is none.
        return senzaRedirect(await daRete);
    }
}

// A visit made by the app itself: the same idea, keyed by the request (it varies with X-Inertia).
async function visitaConCopia(evento) {
    const richiesta = evento.request;
    const cache = await caches.open(CACHE_PAGINE);

    const daRete = fetch(richiesta).then(async (risposta) => {
        if (risposta.ok) {
            await cache.put(richiesta, risposta.clone());
        }

        return risposta;
    });

    try {
        return await entroIlTempo(daRete, ATTESA_RETE_MS);
    } catch {
        const salvata = await cache.match(richiesta);

        if (salvata) {
            evento.waitUntil(daRete.catch(() => undefined));

            return salvata;
        }

        return daRete;
    }
}
