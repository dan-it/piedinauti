// Small helper for the chaperone's screen, which saves each tap with a plain JSON request
// instead of a page visit: several taps can be in flight at once without cancelling each other,
// and a failed save leaves the rest of the screen untouched.

export interface Risposta<T> {
    ok: boolean;
    stato: number;
    dati: T & { message?: string };
}

// Laravel stores the CSRF token in a cookie and expects it back in a header.
const tokenCsrf = (): string => {
    const trovato = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return trovato ? decodeURIComponent(trovato[1]) : '';
};

export async function chiamaJson<T>(url: string, opzioni: { metodo?: 'GET' | 'POST'; corpo?: unknown } = {}): Promise<Risposta<T>> {
    const metodo = opzioni.metodo ?? 'GET';

    const risposta = await fetch(url, {
        method: metodo,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(metodo === 'POST' ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': tokenCsrf() } : {}),
        },
        body: metodo === 'POST' ? JSON.stringify(opzioni.corpo ?? {}) : undefined,
    });

    let dati = {} as Risposta<T>['dati'];
    try {
        dati = await risposta.json();
    } catch {
        // An empty or non-JSON body (e.g. a proxy error page): keep the status only.
    }

    return { ok: risposta.ok, stato: risposta.status, dati };
}
