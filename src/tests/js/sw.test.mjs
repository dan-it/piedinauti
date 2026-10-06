// Tests of public/sw.js in a simulated service worker environment (no browser needed).
// Run:  node tests/js/sw.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const codice = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../../public/sw.js'), 'utf8');
const ORIGINE = 'https://app.test';

// ---- a tiny Cache Storage
class CacheFinta {
    contenuto = new Map();
    chiave(richiesta) {
        const url = new URL(typeof richiesta === 'string' ? richiesta : richiesta.url, ORIGINE).href;
        const inertia = typeof richiesta !== 'string' && richiesta.headers.get('X-Inertia') ? '|inertia' : '';

        return url + inertia;
    }
    async match(richiesta) {
        const salvata = this.contenuto.get(this.chiave(richiesta));

        return salvata ? salvata.clone() : undefined;
    }
    async put(richiesta, risposta) {
        this.contenuto.set(this.chiave(richiesta), risposta.clone());
    }
}
const archivio = new Map();
const caches = {
    async open(nome) {
        if (!archivio.has(nome)) archivio.set(nome, new CacheFinta());

        return archivio.get(nome);
    },
    async keys() {
        return [...archivio.keys()];
    },
    async delete(nome) {
        return archivio.delete(nome);
    },
};

// ---- load the worker with a fake global scope
const ascoltatori = {};
let rete = async () => {
    throw new TypeError('Failed to fetch');
};
const ambiente = {
    self: {
        location: new URL(ORIGINE + '/sw.js'),
        addEventListener: (tipo, f) => (ascoltatori[tipo] = f),
        skipWaiting: () => {},
        clients: { claim: async () => {} },
    },
    caches,
    fetch: (...argomenti) => rete(...argomenti),
};
new Function(...Object.keys(ambiente), codice)(...Object.values(ambiente));

// ---- helpers
const risposta = (corpo, { stato = 200, url = ORIGINE + '/oggi/linee/3', rediretta = false } = {}) => {
    const r = new Response(corpo, { status: stato, headers: { 'Content-Type': 'text/html' } });
    Object.defineProperty(r, 'url', { value: url });
    Object.defineProperty(r, 'redirected', { value: rediretta });

    return r;
};

const evento = (url, { metodo = 'GET', navigazione = false, intestazioni = {} } = {}) => {
    const richiesta = new Request(ORIGINE + url, { method: metodo, headers: intestazioni });
    Object.defineProperty(richiesta, 'mode', { value: navigazione ? 'navigate' : 'cors' });
    const e = { request: richiesta, risposta: null, attese: [] };
    e.respondWith = (p) => (e.risposta = p);
    e.waitUntil = (p) => e.attese.push(p);
    ascoltatori.fetch(e);

    return e;
};

let prove = 0;
const prova = async (nome, fn) => {
    archivio.clear();
    await fn();
    prove++;
    console.log('  ok  ' + nome);
};

const testo = async (e) => (await e.risposta).text();
const nonGestita = (e) => assert.equal(e.risposta, null, 'la richiesta non deve essere gestita dal worker');

await prova('apre la schermata dalla rete e ne tiene una copia', async () => {
    rete = async () => risposta('<html>linea 3 alle 7:40</html>');
    assert.equal(await testo(evento('/oggi/linee/3', { navigazione: true })), '<html>linea 3 alle 7:40</html>');
    const cache = await caches.open('piedinauti-pagine-v1');
    assert.equal(await (await cache.match('/oggi/linee/3')).text(), '<html>linea 3 alle 7:40</html>');
});

await prova('senza rete risponde con la copia salvata', async () => {
    rete = async () => risposta('<html>copia di stamattina</html>');
    await testo(evento('/oggi/linee/3', { navigazione: true }));
    rete = async () => { throw new TypeError('Failed to fetch'); };
    assert.equal(await testo(evento('/oggi/linee/3', { navigazione: true })), '<html>copia di stamattina</html>');
});

await prova('con la rete torna ad aggiornare la copia', async () => {
    rete = async () => risposta('<html>versione 1</html>');
    await testo(evento('/oggi/linee/3', { navigazione: true }));
    rete = async () => risposta('<html>versione 2</html>');
    assert.equal(await testo(evento('/oggi/linee/3', { navigazione: true })), '<html>versione 2</html>');
    rete = async () => { throw new TypeError('x'); };
    assert.equal(await testo(evento('/oggi/linee/3', { navigazione: true })), '<html>versione 2</html>');
});

await prova('"/oggi" porta alla linea: la copia resta sotto /oggi e la risposta non è marcata come rediretta', async () => {
    rete = async () => risposta('<html>linea 3</html>', { url: ORIGINE + '/oggi/linee/3', rediretta: true });
    const e = evento('/oggi', { navigazione: true });
    const r = await e.risposta;
    assert.equal(r.redirected, false, 'il browser rifiuterebbe una risposta rediretta a una navigazione');
    assert.equal(await r.text(), '<html>linea 3</html>');
    rete = async () => { throw new TypeError('x'); };
    assert.equal(await testo(evento('/oggi', { navigazione: true })), '<html>linea 3</html>');
});

await prova('la pagina di accesso (sessione scaduta) non viene conservata come schermata', async () => {
    rete = async () => risposta('<html>accedi</html>', { url: ORIGINE + '/login', rediretta: true });
    await testo(evento('/oggi/linee/3', { navigazione: true }));
    const cache = await caches.open('piedinauti-pagine-v1');
    assert.equal(await cache.match('/oggi/linee/3'), undefined);
});

await prova('una risposta di errore non viene conservata', async () => {
    rete = async () => risposta('<html>errore</html>', { stato: 500 });
    assert.equal(await testo(evento('/oggi/linee/3', { navigazione: true })), '<html>errore</html>');
    const cache = await caches.open('piedinauti-pagine-v1');
    assert.equal(await cache.match('/oggi/linee/3'), undefined);
});

await prova('rete troppo lenta: usa la copia', async () => {
    rete = async () => risposta('<html>copia</html>');
    await testo(evento('/oggi/linee/3', { navigazione: true }));
    rete = () => new Promise((risolvi) => setTimeout(() => risolvi(risposta('<html>lenta</html>')), 4600));
    const inizio = Date.now();
    const e = evento('/oggi/linee/3', { navigazione: true });
    assert.equal(await testo(e), '<html>copia</html>');
    assert.ok(Date.now() - inizio < 4500 && Date.now() - inizio >= 3900, 'attende circa 4 secondi');
    await Promise.all(e.attese); // the slow request finishes in the background and refreshes the copy
    const cache = await caches.open('piedinauti-pagine-v1');
    assert.equal(await (await cache.match('/oggi/linee/3')).text(), '<html>lenta</html>');
});

await prova('senza rete e senza copia: errore del browser (nessuna risposta inventata)', async () => {
    rete = async () => { throw new TypeError('Failed to fetch'); };
    await assert.rejects(async () => (await evento('/oggi/linee/3', { navigazione: true }).risposta));
});

await prova('le visite dell\'app (Inertia) hanno una copia a parte', async () => {
    rete = async () => risposta('{"component":"oggi/Linea"}', { url: ORIGINE + '/oggi/linee/3' });
    assert.equal(await testo(evento('/oggi/linee/3', { intestazioni: { 'X-Inertia': 'true' } })), '{"component":"oggi/Linea"}');
    rete = async () => { throw new TypeError('x'); };
    assert.equal(await testo(evento('/oggi/linee/3', { intestazioni: { 'X-Inertia': 'true' } })), '{"component":"oggi/Linea"}');
    // the page copy and the app-visit copy do not replace each other
    rete = async () => risposta('<html>pagina</html>');
    await testo(evento('/oggi/linee/3', { navigazione: true }));
    rete = async () => { throw new TypeError('x'); };
    assert.equal(await testo(evento('/oggi/linee/3', { intestazioni: { 'X-Inertia': 'true' } })), '{"component":"oggi/Linea"}');
});

await prova('i ricaricamenti parziali non si conservano né si intercettano', async () => {
    nonGestita(evento('/oggi/linee/3', { intestazioni: { 'X-Inertia': 'true', 'X-Inertia-Partial-Data': 'fermate' } }));
});

await prova('i file dell\'app si servono dal telefono dopo il primo scaricamento', async () => {
    let scaricati = 0;
    rete = async () => { scaricati++; return risposta('console.log(1)', { url: ORIGINE + '/build/assets/app-abc.js' }); };
    assert.equal(await testo(evento('/build/assets/app-abc.js')), 'console.log(1)');
    rete = async () => { throw new TypeError('x'); };
    assert.equal(await testo(evento('/build/assets/app-abc.js')), 'console.log(1)');
    assert.equal(scaricati, 1);
});

await prova('il resto non viene toccato: altre pagine, richieste non GET, altri siti, endpoint JSON', async () => {
    nonGestita(evento('/dashboard', { navigazione: true }));
    nonGestita(evento('/login', { navigazione: true }));
    nonGestita(evento('/oggi/fermate/5/presenze', { metodo: 'POST' }));
    nonGestita(evento('/oggi/fermate/5/cerca?q=lu'));
    nonGestita(evento('/oggi/bambini'));
    nonGestita(evento('/presenze', { navigazione: true }));
    const esterna = new Request('https://fonts.bunny.net/css?family=x');
    const e = { request: esterna, risposta: null, respondWith(p) { this.risposta = p; }, waitUntil() {} };
    ascoltatori.fetch(e);
    assert.equal(e.risposta, null);
});

await prova('all\'attivazione si tolgono le copie delle versioni vecchie', async () => {
    await caches.open('piedinauti-pagine-v0');
    await caches.open('piedinauti-file-v0');
    await caches.open('altra-cosa');
    await caches.open('piedinauti-pagine-v1');
    let finito;
    ascoltatori.activate({ waitUntil: (p) => (finito = p) });
    await finito;
    assert.deepEqual((await caches.keys()).sort(), ['altra-cosa', 'piedinauti-pagine-v1']);
});

console.log(`\n${prove} prove superate`);
