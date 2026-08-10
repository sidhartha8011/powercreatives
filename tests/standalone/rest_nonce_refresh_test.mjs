/**
 * Expired REST nonce → re-mint → retry ONCE.
 *
 * Owner report 2026-08-10: "Cookie check failed" on Create Strategy. The nonce is
 * minted once at page load into window.pcmConfig and never refreshed, so once
 * WordPress ages it out (or the session token changes) every write 403s until the
 * tab is reloaded.
 *
 * These drive the REAL logic lifted from app/src/lib/trpc.ts (kept in lockstep by
 * the source-parity check at the end) against a faked fetch, because the failure
 * only exists in the sequence: send → 403 → refresh → resend.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
let passed = 0, failed = 0;
const check = (name, ok) => { ok ? passed++ : failed++; console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}`); };

// ── the logic under test (mirrors trpc.ts) ────────────────────────────────────
function makeClient({ fetchImpl, config }) {
    const setNonce = (n) => { config.nonce = n; };

    async function isNonceError(response) {
        try {
            const body = await response.clone().json();
            return body?.code === 'rest_cookie_invalid_nonce';
        } catch { return false; }
    }

    async function refreshNonce() {
        if (!config.ajaxUrl) return null;
        try {
            const res = await fetchImpl(`${config.ajaxUrl}?action=rest-nonce`, { credentials: 'same-origin' });
            if (!res.ok) return null;
            const nonce = (await res.text()).trim();
            return /^[A-Za-z0-9]{8,64}$/.test(nonce) ? nonce : null;
        } catch { return null; }
    }

    return async function apiFetch(url) {
        const send = (nonce) => fetchImpl(url, { headers: { 'X-WP-Nonce': nonce } });
        let response = await send(config.nonce);
        if (response.status === 403 && (await isNonceError(response))) {
            const fresh = await refreshNonce();
            if (fresh) { setNonce(fresh); response = await send(fresh); }
        }
        if (!response.ok) {
            const err = await response.json().catch(() => ({}));
            const e = new Error(err.message ?? `API error: ${response.status}`);
            e.code = err.code;
            throw e;
        }
        return response.json();
    };
}

// ── fake Response ─────────────────────────────────────────────────────────────
const res = (status, body, isText = false) => {
    const make = () => ({
        ok: status >= 200 && status < 300,
        status,
        json: async () => (typeof body === 'string' ? JSON.parse(body) : body),
        text: async () => (isText ? body : JSON.stringify(body)),
        clone: make,
    });
    return make();
};
const NONCE_403 = () => res(403, { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed.' });

console.log('\nexpired nonce → refresh → retry');

// 1. THE BUG: stale nonce, refresh succeeds, request retried and succeeds.
{
    const calls = [];
    const config = { nonce: 'stale000', ajaxUrl: '/wp-admin/admin-ajax.php' };
    const api = makeClient({
        config,
        fetchImpl: async (url, opts) => {
            calls.push({ url, nonce: opts?.headers?.['X-WP-Nonce'] });
            if (url.includes('action=rest-nonce')) return res(200, 'freshNonce123', true);
            return calls.filter(c => !c.url.includes('rest-nonce')).length === 1
                ? NONCE_403()
                : res(200, { ok: true });
        },
    });
    const out = await api('/wp-json/pcm/v1/strategies');
    check('request succeeds after a single retry', out.ok === true);
    check('retry carried the NEW nonce', calls[calls.length - 1].nonce === 'freshNonce123');
    check('config updated for later requests', config.nonce === 'freshNonce123');
    check('exactly one refresh + two sends', calls.length === 3);
}

// 2. Retry is bounded — a second 403 surfaces the original error, no loop.
{
    let sends = 0;
    const config = { nonce: 'stale000', ajaxUrl: '/wp-admin/admin-ajax.php' };
    const api = makeClient({
        config,
        fetchImpl: async (url) => {
            if (url.includes('action=rest-nonce')) return res(200, 'freshNonce123', true);
            sends++; return NONCE_403();
        },
    });
    let msg = '';
    try { await api('/x'); } catch (e) { msg = e.message; }
    check('gives up after ONE retry (no loop)', sends === 2);
    check('surfaces the original error', msg === 'Cookie check failed.');
}

// 3. Not logged in: core answers "-1" → no retry, original error surfaces.
{
    let sends = 0;
    const config = { nonce: 'stale000', ajaxUrl: '/wp-admin/admin-ajax.php' };
    const api = makeClient({
        config,
        fetchImpl: async (url) => {
            if (url.includes('action=rest-nonce')) return res(200, '-1', true);
            sends++; return NONCE_403();
        },
    });
    try { await api('/x'); } catch { /* expected */ }
    check('"-1" is rejected as a nonce — no retry', sends === 1);
    check('config nonce left untouched', config.nonce === 'stale000');
}

// 4. A 403 that is NOT a nonce problem must not trigger a refresh.
{
    let refreshes = 0, sends = 0;
    const config = { nonce: 'good0000', ajaxUrl: '/wp-admin/admin-ajax.php' };
    const api = makeClient({
        config,
        fetchImpl: async (url) => {
            if (url.includes('action=rest-nonce')) { refreshes++; return res(200, 'x', true); }
            sends++; return res(403, { code: 'pcm_forbidden', message: 'Administrator access required.' });
        },
    });
    let msg = '';
    try { await api('/x'); } catch (e) { msg = e.message; }
    check('permission 403 does not refresh the nonce', refreshes === 0 && sends === 1);
    check('permission error surfaces unchanged', msg === 'Administrator access required.');
}

// 5. Detection is on the CODE, not the message — this install runs Swedish.
{
    const calls = [];
    const config = { nonce: 'stale000', ajaxUrl: '/wp-admin/admin-ajax.php' };
    const api = makeClient({
        config,
        fetchImpl: async (url, opts) => {
            calls.push(opts?.headers?.['X-WP-Nonce']);
            if (url.includes('action=rest-nonce')) return res(200, 'freshNonce123', true);
            return calls.filter(Boolean).length === 1
                ? res(403, { code: 'rest_cookie_invalid_nonce', message: 'Kontroll av kaka misslyckades.' })
                : res(200, { ok: true });
        },
    });
    const out = await api('/x');
    check('translated message still recovers (code-matched)', out.ok === true);
}

// 6. Body is cloned — the error path can still read it after the sniff.
{
    const config = { nonce: 'stale000', ajaxUrl: null }; // no ajaxUrl → no refresh possible
    const api = makeClient({ config, fetchImpl: async () => NONCE_403() });
    let msg = '';
    try { await api('/x'); } catch (e) { msg = e.message; }
    check('response body still readable after clone()', msg === 'Cookie check failed.');
}

// ── source parity: the shipped file must still contain this logic ─────────────
console.log('\nsource parity with app/src/lib/trpc.ts');
{
    const src = readFileSync(join(root, 'app/src/lib/trpc.ts'), 'utf8');
    check('matches on rest_cookie_invalid_nonce', src.includes('rest_cookie_invalid_nonce'));
    check('re-mints via core action=rest-nonce', src.includes('action=rest-nonce'));
    check('uses response.clone() before reading', src.includes('response.clone()'));
    check('binds the heartbeat tick', src.includes('heartbeat-tick') && src.includes('rest_nonce'));
    // Exactly two sends total — the initial one plus ONE retry — and no loop
    // construct around them, so a persistently-bad nonce can never spin.
    const sends = (src.match(/await send\(/g) || []).length;
    check('exactly 2 send sites (initial + one retry)', sends === 2);
    check('retry is not inside a loop', !/(while|for)\s*\([^)]*\)\s*\{[^}]*await send\(/s.test(src));
}

console.log(`\n  passed: ${passed}   failed: ${failed}`);
process.exit(failed === 0 ? 0 : 1);
