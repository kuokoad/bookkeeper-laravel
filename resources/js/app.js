/*
  Select boxes open the app's own dropdown panel on a desktop (a fine pointer),
  styled like the Create Transaction menu. The native <select> stays the real
  control: it keeps its value, its form field, Livewire's wire:model and the
  screen reader's view of it; this list only sets the selected option and fires
  input + change, as the browser would. Phones and tablets keep the native
  picker. Multi-line, disabled and opted-out (data-native) selects are left alone.
*/
const desktop = window.matchMedia('(pointer: fine)');
let open = null; // { select, list, options, active, typed, typedAt }

const usable = (s) => s instanceof HTMLSelectElement && !s.multiple && s.size <= 1 && !s.disabled && !s.hasAttribute('data-native') && desktop.matches;

function close(refocus = true) {
    if (!open) return;
    const { select, list } = open;
    open = null;
    list.remove();
    select.removeAttribute('aria-expanded');
    if (refocus) select.focus();
}

function setActive(i) {
    const { options } = open;
    if (i < 0 || i >= options.length) return;
    options[open.active]?.el.classList.remove('is-active');
    open.active = i;
    const el = options[i].el;
    el.classList.add('is-active');
    open.list.setAttribute('aria-activedescendant', el.id);
    el.scrollIntoView({ block: 'nearest' });
}

/** The next enabled option from `from` in direction `dir`, or `from` itself. */
function step(from, dir) {
    for (let i = from + dir; i >= 0 && i < open.options.length; i += dir) {
        if (!open.options[i].disabled) return i;
    }
    return from;
}

function choose(i) {
    const { select, options } = open;
    const entry = options[i];
    if (!entry || entry.disabled) return;
    // Livewire replaced the box or its options while the list was open: choose nothing rather than the wrong one.
    if (!select.isConnected || !select.contains(entry.option)) return close(false);
    const changed = select.selectedIndex !== entry.option.index;
    select.selectedIndex = entry.option.index;
    close();
    if (changed) {
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function place() {
    const { select, list } = open;
    const r = select.getBoundingClientRect();
    const below = window.innerHeight - r.bottom - 8;
    const above = r.top - 8;
    const up = below < 200 && above > below;
    list.style.minWidth = `${r.width}px`;
    list.style.maxWidth = `${Math.max(r.width, Math.min(480, window.innerWidth - 16))}px`;
    list.style.maxHeight = `${Math.min(320, up ? above : below)}px`;
    list.style.left = `${Math.max(8, Math.min(r.left, window.innerWidth - list.offsetWidth - 8))}px`;
    list.style.top = up ? `${r.top - list.offsetHeight - 4}px` : `${r.bottom + 4}px`;
}

function show(select) {
    close(false);
    const list = document.createElement('ul');
    list.className = 'select-list dropdown-panel';
    list.setAttribute('role', 'listbox');
    list.tabIndex = -1;
    const label = select.labels?.[0]?.textContent.trim() || select.getAttribute('aria-label');
    if (label) list.setAttribute('aria-label', label);

    const options = [];
    for (const child of select.children) {
        const group = child instanceof HTMLOptGroupElement ? child : null;
        if (group) {
            const head = document.createElement('li');
            head.className = 'select-list-group';
            head.setAttribute('role', 'presentation');
            head.textContent = group.label;
            list.append(head);
        }
        for (const option of group ? group.children : [child]) {
            if (!(option instanceof HTMLOptionElement) || option.hidden) continue;
            const i = options.length;
            const disabled = option.disabled || Boolean(group?.disabled);
            const el = document.createElement('li');
            el.id = `select-list-option-${i}`;
            el.setAttribute('role', 'option');
            el.setAttribute('aria-selected', String(option.selected));
            if (disabled) el.setAttribute('aria-disabled', 'true');
            el.textContent = option.textContent;
            el.addEventListener('mousedown', (e) => e.preventDefault());
            el.addEventListener('click', () => choose(i));
            el.addEventListener('mousemove', () => open && open.active !== i && setActive(i));
            list.append(el);
            options.push({ option, el, disabled });
        }
    }
    if (options.length === 0) return;

    document.body.append(list);
    open = { select, list, options, active: -1, typed: '', typedAt: 0 };
    select.setAttribute('aria-expanded', 'true');
    place();
    const current = options.findIndex((o) => o.option.selected);
    setActive(current >= 0 ? current : step(-1, 1));
    list.addEventListener('keydown', onListKey);
    list.focus({ preventScroll: true });
}

function onListKey(e) {
    const { active, options } = open;
    const keys = {
        ArrowDown: () => setActive(step(active, 1)),
        ArrowUp: () => (e.altKey ? choose(active) : setActive(step(active, -1))),
        Home: () => setActive(step(-1, 1)),
        End: () => setActive(step(options.length, -1)),
        PageDown: () => setActive(step(Math.min(active + 9, options.length), -1)),
        PageUp: () => setActive(step(Math.max(active - 9, -1), 1)),
        Enter: () => choose(active),
        ' ': () => (Date.now() - open.typedAt < 700 ? typeAhead(' ') : choose(active)),
        Escape: () => close(),
    };
    if (e.key === 'Tab') {
        close();
    } else if (keys[e.key]) {
        e.preventDefault();
        keys[e.key]();
    } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        typeAhead(e.key);
    }
}

/** Jump to the next option starting with what was typed, as a native select does. */
function typeAhead(char) {
    const now = Date.now();
    open.typed = (now - open.typedAt < 700 ? open.typed : '') + char.toLowerCase();
    open.typedAt = now;
    const { options, active, typed } = open;
    const from = typed.length === 1 ? active + 1 : active;
    for (let k = 0; k < options.length; k++) {
        const i = (from + k) % options.length;
        if (!options[i].disabled && options[i].option.textContent.trim().toLowerCase().startsWith(typed)) {
            setActive(i);
            return;
        }
    }
}

document.addEventListener('mousedown', (e) => {
    if (open && open.list.contains(e.target)) return;
    const select = e.target instanceof Element ? e.target.closest('select') : null;
    if (open) {
        const again = select === open.select;
        close(false);
        if (again) {
            e.preventDefault(); // a second click on the box closes the list, as natively
            return;
        }
    }
    if (e.button !== 0 || !usable(select)) return;
    e.preventDefault();
    select.focus();
    show(select);
});

document.addEventListener('keydown', (e) => {
    const select = e.target;
    if (open || !usable(select)) return;
    const opens = (e.altKey && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) || e.key === 'F4' || e.key === ' ';
    if (!opens) return; // arrows still step the value and Enter still submits, as natively
    e.preventDefault();
    show(select);
});

window.addEventListener('resize', () => close(false));
document.addEventListener('scroll', (e) => open && !open.list.contains(e.target) && close(false), true);
document.addEventListener('livewire:navigating', () => close(false));
