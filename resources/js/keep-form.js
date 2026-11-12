/*
  A save sent after the sign-in ended (signed out or switched user in another
  tab, a password or access change, a week idle) is sent to sign-in, or
  refused with 419, and Livewire used to leave the page empty. Instead, what was typed on
  the page's Livewire forms is kept in this tab (sessionStorage), for this page
  and this person only; the reload goes to sign-in, sign-in comes back here,
  and the kept values are put back for them to check and save again.

  What is kept: each component's properties that its fields are bound to
  (wire:model), whole. Ids and counters are #[Locked] and come fresh from the
  server, so nothing here can change which record a form saves to.
*/
const PREFIX = 'nuna.kept:';
const me = () => document.querySelector('meta[name="nuna-user"]')?.content ?? '';
const key = () => PREFIX + location.pathname + location.search;

const store = (fn) => {
    try {
        return fn(window.sessionStorage);
    } catch {
        return null; // storage switched off: behave as before
    }
};

/** The properties a component's own fields are bound to: "rows" for wire:model="rows.0.qty". */
function boundRoots(component) {
    const roots = new Set();
    for (const el of component.el.querySelectorAll('input, select, textarea')) {
        if (el.closest('[wire\\:id]') !== component.el || el.type === 'password' || el.type === 'file') continue;
        for (const attr of el.attributes) {
            if (attr.name.startsWith('wire:model')) roots.add(attr.value.split('.')[0]);
        }
    }
    return [...roots];
}

function keep() {
    const user = me();
    if (user === '') return;
    const forms = window.Livewire.all()
        .map((c) => ({ name: c.name, data: Object.fromEntries(boundRoots(c).map((r) => [r, c.$wire.get(r)])) }))
        .filter((f) => Object.keys(f.data).length > 0);
    if (forms.length > 0) store((s) => s.setItem(key(), JSON.stringify({ user, forms })));
}

function putBack() {
    const kept = store((s) => {
        const raw = s.getItem(key());
        s.removeItem(key());
        return raw === null ? null : JSON.parse(raw);
    });
    // Another person signed in on this tab: their page stays as the server drew it.
    if (!kept || kept.user !== me()) return;
    const components = window.Livewire.all();
    let restored = false;
    for (const form of kept.forms) {
        const c = components.find((x) => x.name === form.name);
        if (!c) continue;
        for (const [prop, value] of Object.entries(form.data)) c.$wire.$set(prop, value, false);
        c.$wire.$commit();
        restored = true;
    }
    if (restored) {
        window.dispatchEvent(new CustomEvent('toast', {
            detail: { message: 'You were signed out before your last save went through. What you typed is back: check it and save again.', tone: 'warning' },
        }));
    }
}

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ respond, fail }) => {
        // Sent to sign-in instead of saving: Livewire follows the redirect itself.
        respond(({ response }) => {
            if (response.redirected && new URL(response.url).pathname === '/login') keep();
        });
        fail(({ status, preventDefault }) => {
            if (status !== 419) return;
            keep();
            preventDefault(); // not Livewire's "This page has expired" prompt
            window.location.reload();
        });
    });
});

document.addEventListener('livewire:initialized', putBack);

document.addEventListener('DOMContentLoaded', () => {
    const note = document.querySelector('[data-kept-form-note]');
    const waiting = store((s) => Object.keys(s).some((k) => k.startsWith(PREFIX)));
    if (note && waiting) note.hidden = false;
});
