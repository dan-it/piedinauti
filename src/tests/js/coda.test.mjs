import 'fake-indexeddb/auto';
import assert from 'node:assert/strict';
import { filtra, normalizza } from './elenco.mjs';
import { aggiungiAzione, elencoAzioni, rimuoviAzione, contaAzioni, salvaDato, leggiDato, svuotaTutto, classifica, inviaCoda, nuovaAzione } from './coda.mjs';

let prove = 0;
const prova = async (nome, fn) => {
    await svuotaTutto();
    await fn();
    prove++;
    console.log('  ok  ' + nome);
};

const azione = (chiave, extra = {}) => nuovaAzione({ chiave, tipo: 'presenza', url: '/x/' + chiave, corpo: { chiave }, riferimento: {}, ...extra });
const ok = (dati = {}) => ({ ok: true, stato: 200, dati });
const risposta = (stato, dati = {}) => ({ ok: stato >= 200 && stato < 300, stato, dati });
const pausa = (ms) => new Promise((r) => setTimeout(r, ms));

await prova('aggiungere e leggere in ordine di creazione', async () => {
    const a = azione('a'); a.creata = 300;
    const b = azione('b'); b.creata = 100;
    const c = azione('c'); c.creata = 200;
    for (const x of [a, b, c]) await aggiungiAzione(x);
    assert.deepEqual((await elencoAzioni()).map((x) => x.chiave), ['b', 'c', 'a']);
    assert.equal(await contaAzioni(), 3);
});

await prova('una nuova azione con la stessa chiave sostituisce la vecchia', async () => {
    await aggiungiAzione(azione('p:1:7', { corpo: { presente: true } }));
    await aggiungiAzione(azione('p:2:7'));
    await aggiungiAzione(azione('p:1:7', { corpo: { presente: false } }));
    const elenco = await elencoAzioni();
    assert.equal(elenco.length, 2);
    assert.equal(elenco.find((x) => x.chiave === 'p:1:7').corpo.presente, false);
});

await prova('rimuovere una azione', async () => {
    const a = azione('a'); await aggiungiAzione(a); await aggiungiAzione(azione('b'));
    await rimuoviAzione(a.id);
    assert.deepEqual((await elencoAzioni()).map((x) => x.chiave), ['b']);
    await rimuoviAzione('non-esiste');
    assert.equal(await contaAzioni(), 1);
});

await prova('dati salvati e svuotamento totale', async () => {
    await salvaDato('bambini', { elenco: [1, 2, 3] });
    assert.deepEqual(await leggiDato('bambini'), { elenco: [1, 2, 3] });
    assert.equal(await leggiDato('altro'), undefined);
    await aggiungiAzione(azione('a'));
    await svuotaTutto();
    assert.equal(await leggiDato('bambini'), undefined);
    assert.equal(await contaAzioni(), 0);
});

await prova('classificazione delle risposte', async () => {
    for (const s of [200, 201, 204]) assert.equal(classifica(s), 'ok');
    for (const s of [401, 419]) assert.equal(classifica(s), 'sessione');
    for (const s of [0, 408, 429, 500, 502, 503, 504]) assert.equal(classifica(s), 'server');
    for (const s of [400, 403, 404, 422]) assert.equal(classifica(s), 'rifiuto');
});

await prova('invio riuscito: in ordine, e la coda si svuota', async () => {
    const a = azione('a'); a.creata = 2;
    const b = azione('b'); b.creata = 1;
    await aggiungiAzione(a); await aggiungiAzione(b);
    const inviate = [];
    const r = await inviaCoda(async (x) => { inviate.push(x.chiave); return ok(); });
    assert.deepEqual(inviate, ['b', 'a']);
    assert.deepEqual(r, { inviate: 2, rifiutate: 0, interrotto: null });
    assert.equal(await contaAzioni(), 0);
});

await prova('senza rete: si ferma e conserva tutto, poi riprende', async () => {
    await aggiungiAzione(Object.assign(azione('a'), { creata: 1 }));
    await aggiungiAzione(Object.assign(azione('b'), { creata: 2 }));
    let chiamate = 0;
    const r1 = await inviaCoda(async () => { chiamate++; throw new TypeError('Failed to fetch'); });
    assert.deepEqual(r1, { inviate: 0, rifiutate: 0, interrotto: 'rete' });
    assert.equal(chiamate, 1, 'si ferma al primo errore di rete');
    assert.equal(await contaAzioni(), 2);

    const r2 = await inviaCoda(async () => ok());
    assert.deepEqual(r2, { inviate: 2, rifiutate: 0, interrotto: null });
    assert.equal(await contaAzioni(), 0);
});

await prova('un rifiuto del server toglie l\'azione e si va avanti', async () => {
    await aggiungiAzione(Object.assign(azione('a'), { creata: 1 }));
    await aggiungiAzione(Object.assign(azione('b'), { creata: 2 }));
    await aggiungiAzione(Object.assign(azione('c'), { creata: 3 }));
    const esiti = [];
    const r = await inviaCoda(
        async (x) => (x.chiave === 'b' ? risposta(403, { message: 'Il tempo è scaduto.' }) : ok()),
        (e) => esiti.push(e.tipo + ':' + e.azione.chiave + (e.messaggio ? ':' + e.messaggio : '')),
    );
    assert.deepEqual(r, { inviate: 2, rifiutate: 1, interrotto: null });
    assert.deepEqual(esiti, ['inviata:a', 'rifiutata:b:Il tempo è scaduto.', 'inviata:c']);
    assert.equal(await contaAzioni(), 0);
});

await prova('sessione scaduta o errore del server: si ferma e conserva', async () => {
    for (const [stato, atteso] of [[419, 'sessione'], [401, 'sessione'], [503, 'server'], [429, 'server']]) {
        await svuotaTutto();
        await aggiungiAzione(Object.assign(azione('a'), { creata: 1 }));
        await aggiungiAzione(Object.assign(azione('b'), { creata: 2 }));
        const r = await inviaCoda(async () => risposta(stato, { message: 'x' }));
        assert.deepEqual(r, { inviate: 0, rifiutate: 0, interrotto: atteso }, 'stato ' + stato);
        assert.equal(await contaAzioni(), 2, 'conservate con ' + stato);
    }
});

await prova('si ferma a metà: le prime sono inviate, le altre restano', async () => {
    await aggiungiAzione(Object.assign(azione('a'), { creata: 1 }));
    await aggiungiAzione(Object.assign(azione('b'), { creata: 2 }));
    await aggiungiAzione(Object.assign(azione('c'), { creata: 3 }));
    const r = await inviaCoda(async (x) => (x.chiave === 'b' ? risposta(500) : ok()));
    assert.deepEqual(r, { inviate: 1, rifiutate: 0, interrotto: 'server' });
    assert.deepEqual((await elencoAzioni()).map((x) => x.chiave), ['b', 'c']);
});

await prova('due richieste di invio insieme: si invia una volta sola', async () => {
    await aggiungiAzione(azione('a')); await aggiungiAzione(azione('b'));
    let chiamate = 0;
    const lento = async () => { chiamate++; await pausa(20); return ok(); };
    const [r1, r2] = await Promise.all([inviaCoda(lento), inviaCoda(lento)]);
    assert.equal(chiamate, 2, 'ogni azione una volta');
    assert.deepEqual(r1, r2);
    assert.equal(await contaAzioni(), 0);
});

await prova('un tocco nuovo durante l\'invio non va perso', async () => {
    const vecchia = azione('p:1:7', { corpo: { presente: true } }); vecchia.creata = 1;
    await aggiungiAzione(vecchia);
    const inviati = [];
    const r = await inviaCoda(async (x) => {
        inviati.push(x.corpo.presente);
        // while the old tap is on its way, the chaperone taps again on the same child
        await aggiungiAzione(Object.assign(azione('p:1:7', { corpo: { presente: false } }), { creata: 2 }));
        return ok();
    });
    assert.deepEqual(inviati, [true]);
    assert.equal(r.inviate, 1);
    const resto = await elencoAzioni();
    assert.equal(resto.length, 1, 'il tocco nuovo è ancora in coda');
    assert.equal(resto[0].corpo.presente, false);
    // the next run sends it
    const r2 = await inviaCoda(async (x) => { inviati.push(x.corpo.presente); return ok(); });
    assert.deepEqual(inviati, [true, false]);
    assert.equal(r2.inviate, 1);
    assert.equal(await contaAzioni(), 0);
});

await prova('la stessa azione inviata di nuovo dopo una risposta persa resta valida', async () => {
    // the server stored it but the answer never arrived: the action stays queued and is sent again
    await aggiungiAzione(azione('a'));
    const r1 = await inviaCoda(async () => { throw new TypeError('network'); });
    assert.equal(r1.interrotto, 'rete');
    const visti = [];
    const r2 = await inviaCoda(async (x) => { visti.push(x.corpo.chiave); return ok(); });
    assert.deepEqual(visti, ['a']);
    assert.equal(r2.inviate, 1);
});

const elenco = [
    { id: 1, nome: 'Anna Bianchi' }, { id: 2, nome: 'Luca Rossi' }, { id: 3, nome: 'Lucia Verdi' },
    { id: 4, nome: 'Zoë' }, { id: 5, nome: 'Marco' },
];

await prova('ricerca locale: più parole in qualsiasi ordine, senza accenti né maiuscole', async () => {
    assert.equal(normalizza('Zoë Èmile'), 'zoe emile');
    assert.deepEqual(filtra(elenco, 'luc', new Set()).map((b) => b.id), [2, 3]);
    assert.deepEqual(filtra(elenco, 'ROSSI luca', new Set()).map((b) => b.id), [2]);
    assert.deepEqual(filtra(elenco, 'luca rossi', new Set()).map((b) => b.id), [2]);
    assert.deepEqual(filtra(elenco, 'zoe', new Set()).map((b) => b.id), [4]);
});

await prova('ricerca locale: minimo due lettere, esclusi e limite', async () => {
    assert.deepEqual(filtra(elenco, 'l', new Set()), []);
    assert.deepEqual(filtra(elenco, '  ', new Set()), []);
    assert.deepEqual(filtra(elenco, 'luc', new Set([2])).map((b) => b.id), [3]);
    const molti = Array.from({ length: 30 }, (_, i) => ({ id: i, nome: 'Marco ' + i }));
    assert.equal(filtra(molti, 'marco', new Set()).length, 10);
    assert.equal(filtra(molti, 'marco', new Set(), 3).length, 3);
});

console.log(`\n${prove} prove superate`);
