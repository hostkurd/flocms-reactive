import htmx from 'htmx.org';
import Alpine from '@alpinejs/csp';
import morph from '@alpinejs/morph';
import { DialogManager } from './dialogs';
import '../css/reactive.css';

type Scalar = string | number | boolean;
// A job without state sends the component's values as they are when the
// request actually leaves, so edits made while it waited are included.
type Job = { sequence: number; action: string; state?: Record<string, Scalar> };
type Runtime = {
  root: HTMLElement; snapshot: string; token: string; values: Record<string, Scalar>;
  sequence: number; busy: boolean; pending?: Job; timer?: ReturnType<typeof setTimeout>;
  dialogs: DialogManager;
  activeAction: string;
  /** Bindings edited locally since the last request left (not yet on the server). */
  dirty: Set<string>;
  /** When the current snapshot was signed (client clock) and how long it lasts. */
  signedAt: number; ttl: number; renewing: boolean;
  messages: Record<string, string>;
};
type Mode = { kind: 'live'; delay: number } | { kind: 'lazy' | 'blur' | 'defer' };
const RENEW = '$renew';
const FALLBACK: Record<string, string> = {
  session: 'Your session ended. Reload the page.', forbidden: 'You no longer have permission for this component.',
  missing: 'The record or action is no longer available. Close the dialog and refresh the page.',
  changed: 'The component changed. Reload the page.', expired: 'This page expired. Reload the page.',
  failed: 'The update failed. Check your connection and try again.', invalid: 'Invalid component response. Reload the page.',
};
const components = new Map<string, Runtime>();
const requests = new WeakMap<XMLHttpRequest, { runtime: Runtime; job: Job }>();

// Local bundles only. Keep sensitive component HTML out of the htmx history cache.
htmx.config.allowEval = false;
htmx.config.allowScriptTags = false;
htmx.config.includeIndicatorStyles = false;
htmx.config.historyCacheSize = 0;
htmx.config.selfRequestsOnly = true;
Alpine.plugin(morph);

function initialize(root: ParentNode = document): void {
  root.querySelectorAll<HTMLElement>('[data-rx-component]').forEach(element => {
    if (components.has(element.id)) return;
    let state: Runtime;
    const dialogs = new DialogManager(element, () => !accepting(state), action => schedule(state, action, true, {}));
    const ttl = Number(element.dataset.rxTtl);
    state = {
      root: element, snapshot: element.dataset.rxSnapshot!, token: element.dataset.rxToken!,
      values: JSON.parse(element.dataset.rxState!), sequence: 0, busy: false, dialogs, activeAction: 'refresh',
      dirty: new Set(), signedAt: Date.now(), ttl: Number.isFinite(ttl) && ttl >= 60 ? ttl : 1800, renewing: false,
      messages: readMessages(element),
    };
    components.set(element.id, state);
    dialogs.sync();
    element.dispatchEvent(new CustomEvent('flo-reactive:initialized', { bubbles: true }));
  });
}

function readMessages(element: HTMLElement): Record<string, string> {
  try {
    const parsed: unknown = JSON.parse(element.dataset.rxMessages ?? '{}');
    if (parsed && typeof parsed === 'object') {
      const messages = { ...FALLBACK };
      Object.entries(parsed as Record<string, unknown>).forEach(([key, value]) => { if (typeof value === 'string' && value !== '') messages[key] = value; });
      return messages;
    }
  } catch { /* use English */ }
  return { ...FALLBACK };
}

/**
 * data-rx-model on a bound control: "live" (default, 300 ms after typing
 * stops), "debounce:600" (live with another delay), "lazy" (when the value is
 * committed: change), "blur" (when the control loses focus) or "defer" (only
 * with the next request, e.g. an action).
 */
function mode(control: HTMLElement): Mode {
  const value = (control.dataset.rxModel ?? '').trim().toLowerCase();
  if (value === 'lazy' || value === 'blur' || value === 'defer') return { kind: value };
  const debounce = /^debounce:(\d{1,5})(?:ms)?$/.exec(value);
  if (debounce) return { kind: 'live', delay: Math.min(Number(debounce[1]), 10000) };
  return { kind: 'live', delay: 300 };
}

function read(control: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement): Scalar {
  return control instanceof HTMLInputElement && control.type === 'checkbox' ? control.checked : control.value;
}

function bound(target: EventTarget | null): HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null {
  return target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement ? target : null;
}

/** Actions may queue behind a plain update, but never behind another action. */
function accepting(state: Runtime): boolean {
  if (state.pending && state.pending.action !== 'refresh') return false;
  return !state.busy || state.activeAction === 'refresh';
}

function runtime(element: Element): Runtime | undefined {
  const root = element.closest<HTMLElement>('[data-rx-component]');
  return root ? components.get(root.id) : undefined;
}

function status(state: Runtime, text: string): void {
  state.root.querySelectorAll<HTMLElement>('[data-rx-status]').forEach(message => { message.textContent = text; });
}

function loading(state: Runtime, busy: boolean): void {
  state.root.setAttribute('aria-busy', String(busy));
  // Buttons lock only while an action runs. During a plain update (typing, a
  // lazy field losing focus to the Save button) a click is queued instead.
  const locking = busy && state.activeAction !== 'refresh';
  state.root.querySelectorAll<HTMLButtonElement>('[data-rx-action], [data-rx-dialog-close], form[data-rx-submit] button[type="submit"], form[data-rx-submit] button:not([type])').forEach(button => {
    if (locking) {
      if (button.dataset.rxWasDisabled !== undefined) return;
      button.dataset.rxWasDisabled = String(button.disabled);
      button.disabled = true;
    } else {
      if (button.dataset.rxWasDisabled !== undefined) {
        button.disabled = button.dataset.rxWasDisabled === 'true';
        delete button.dataset.rxWasDisabled;
      }
    }
  });
  state.root.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('dialog[data-rx-dialog] [data-rx-bind]').forEach(control => {
    if (busy && state.activeAction !== 'refresh') {
      control.dataset.rxControlDisabled = String(control.disabled);
      control.disabled = true;
    } else if (!busy && control.dataset.rxControlDisabled !== undefined) {
      control.disabled = control.dataset.rxControlDisabled === 'true';
      delete control.dataset.rxControlDisabled;
    }
  });
  if (busy && state.activeAction !== 'refresh') state.dialogs.focusBusy();
}

function send(state: Runtime, job: Job): void {
  // Serialize requests per component, including mutations. New edits replace
  // only the pending refresh; an already running action is never replayed.
  if (state.busy) {
    // A queued action is never replaced by a later plain update: it sends the
    // newest values anyway, and takes the update's place in the sequence.
    if (state.pending && state.pending.action !== 'refresh' && job.action === 'refresh' && !job.state) state.pending = { ...state.pending, sequence: job.sequence };
    else state.pending = job;
    return;
  }
  // Explicit job state (e.g. {} for Discard) intentionally drops local edits.
  const current = job.state ?? { ...state.values };
  state.dirty.clear();
  state.busy = true;
  state.activeAction = job.action;
  status(state, '');
  loading(state, true);
  const values: Record<string, string> = {
    _snapshot: state.snapshot, _token: state.token, _action: job.action,
    _query: window.location.search, _sequence: String(job.sequence),
  };
  Object.entries(current).forEach(([key, value]) => {
    values[`state[${key}]`] = typeof value === 'boolean' ? (value ? '1' : '0') : String(value);
  });
  void htmx.ajax('POST', state.root.dataset.rxEndpoint, {
    source: state.root, target: state.root, swap: 'none', values,
    headers: { 'X-CSRF-TOKEN': state.token, 'X-RX-Sequence': String(job.sequence) },
  }).catch(() => { /* htmx:afterRequest reports transport failures */ });
}

function schedule(state: Runtime, action = 'refresh', immediate = false, values?: Record<string, Scalar>, delay = 300): void {
  if (state.timer) clearTimeout(state.timer);
  state.timer = undefined;
  const sequence = ++state.sequence;
  // Search updates should supersede an older page selection while waiting.
  const job: Job = { sequence, action, state: values ? { ...values } : undefined };
  if (immediate || delay <= 0) send(state, job);
  else state.timer = setTimeout(() => { state.timer = undefined; send(state, job); }, delay);
}

/** Refresh a mounted component through its normal signed/authorized endpoint. */
export function refresh(key: string): boolean {
  const state = components.get(`rx-${key}`);
  if (!state) return false;
  schedule(state, 'refresh', true);
  return true;
}

document.addEventListener('input', event => {
  const input = bound(event.target);
  if (!input) return;
  const key = input.dataset.rxBind;
  const state = runtime(input);
  if (!key || !state || !(key in state.values)) return;
  state.values[key] = read(input);
  state.dirty.add(key);
  // Components declare their own resets with data-rx-reset="page:1".
  const reset = input.dataset.rxReset;
  if (reset) {
    const [name, value] = reset.split(':', 2);
    if (name in state.values) {
      state.values[name] = value;
      state.root.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('[data-rx-bind]').forEach(control => {
        if (control.dataset.rxBind === name) {
          if (control instanceof HTMLInputElement && control.type === 'checkbox') control.checked = value === '1';
          else control.value = value;
        }
      });
      state.dirty.add(name);
    }
  }
  const binding = mode(input);
  if (binding.kind === 'live') schedule(state, 'refresh', false, undefined, binding.delay);
});

// "lazy" sends when the value is committed, "blur" when focus leaves an edited control.
document.addEventListener('change', event => {
  const input = bound(event.target);
  const state = input ? runtime(input) : undefined;
  if (!input || !state || !input.dataset.rxBind || !(input.dataset.rxBind in state.values)) return;
  if (mode(input).kind === 'lazy' && state.dirty.has(input.dataset.rxBind)) schedule(state, 'refresh', true);
});
document.addEventListener('focusout', event => {
  const input = bound(event.target);
  const state = input ? runtime(input) : undefined;
  if (!input || !state || !input.dataset.rxBind || !(input.dataset.rxBind in state.values)) return;
  if (mode(input).kind === 'blur' && state.dirty.has(input.dataset.rxBind)) schedule(state, 'refresh', true);
});

document.addEventListener('click', event => {
  if (!(event.target instanceof Element)) return;
  const close = event.target.closest<HTMLButtonElement>('[data-rx-dialog-close]');
  if (close) {
    event.preventDefault();
    if (!close.disabled) runtime(close)?.dialogs.dismiss(close.dataset.rxDialogClose!);
    return;
  }
  const button = event.target.closest<HTMLButtonElement>('[data-rx-action]');
  if (!button || button.disabled) return;
  const state = runtime(button);
  if (!state) return;
  event.preventDefault();
  if (!accepting(state)) return;
  if (button.dataset.rxDialogTrigger) state.dialogs.remember(button.dataset.rxDialogTrigger, button);
  if (button.dataset.rxSet && button.dataset.rxSet in state.values) {
    state.values[button.dataset.rxSet] = button.dataset.rxValue ?? '';
  }
  schedule(state, button.dataset.rxAction, true);
});

document.addEventListener('submit', event => {
  if (!(event.target instanceof HTMLFormElement) || !event.target.hasAttribute('data-rx-submit')) return;
  const state = runtime(event.target);
  event.preventDefault();
  if (!state || !accepting(state)) return;
  // Read the submitted controls too: autofill and programmatic value changes
  // need not emit an input event. Only registered writable bindings are sent.
  event.target.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('[data-rx-bind]').forEach(input => {
    const key = input.dataset.rxBind!;
    if (key in state.values) state.values[key] = read(input);
  });
  schedule(state, event.target.dataset.rxSubmit || 'refresh', true);
});

document.addEventListener('htmx:beforeRequest', ((event: CustomEvent) => {
  const root = event.detail.elt as HTMLElement;
  const state = components.get(root.id);
  if (!state) return;
  requests.set(event.detail.xhr, { runtime: state, job: {
    sequence: Number(event.detail.requestConfig.headers['X-RX-Sequence']),
    action: event.detail.requestConfig.parameters._action,
  } });
}) as EventListener);

document.addEventListener('htmx:beforeSwap', ((event: CustomEvent) => {
  const entry = requests.get(event.detail.xhr);
  if (!entry) return;
  event.detail.shouldSwap = false;
  const { runtime: state, job } = entry;
  if (![200, 422].includes(event.detail.xhr.status)) return;
  const template = document.createElement('template');
  template.innerHTML = event.detail.xhr.responseText;
  const next = template.content.firstElementChild as HTMLElement | null;
  if (!next || next.id !== state.root.id || next.dataset.rxComponent !== state.root.dataset.rxComponent) {
    status(state, state.messages.invalid); return;
  }
  state.snapshot = next.dataset.rxSnapshot!;
  state.signedAt = Date.now();
  const targets: unknown = JSON.parse(next.dataset.rxRefresh ?? '[]');
  if (event.detail.xhr.status === 200 && Array.isArray(targets)) targets.forEach(key => {
    if (typeof key === 'string' && `rx-${key}` !== state.root.id) refresh(key);
  });
  // Always renew the signed snapshot, but never display results for older edits.
  if (job.sequence !== state.sequence) return;
  const focused = document.activeElement as HTMLInputElement | HTMLTextAreaElement | null;
  const selection = focused && 'selectionStart' in focused ? [focused.selectionStart, focused.selectionEnd] : null;
  state.root.dispatchEvent(new CustomEvent('flo-reactive:before-update', { bubbles: true }));
  loading(state, false);
  Alpine.morph(state.root, next.outerHTML, {
    key: (element: HTMLElement) => element.id,
    updating: (element: HTMLElement, replacement: HTMLElement, _childrenOnly: () => void, skip: () => void) => {
      // A widget that owns its DOM (a rich text editor, a map) marks its root
      // data-rx-ignore="key": morph leaves it untouched while the key matches,
      // and replaces it (for the widget to start again) when the key changes.
      if (element instanceof HTMLElement && replacement instanceof HTMLElement && element.dataset.rxIgnore !== undefined
        && element.dataset.rxIgnore === replacement.dataset.rxIgnore) { skip(); return; }
      // The native open attribute belongs to the dialog lifecycle, not morph.
      if (element instanceof HTMLDialogElement && element.open) replacement.setAttribute('open', '');
    },
  });
  // Server state wins, except for bindings edited after this request left
  // (e.g. a deferred field typed into meanwhile): those stay as typed.
  const local = state.values;
  state.values = JSON.parse(state.root.dataset.rxState!);
  state.dirty.forEach(key => { if (key in state.values && key in local) state.values[key] = local[key]; else state.dirty.delete(key); });
  state.messages = readMessages(state.root);
  state.root.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('[data-rx-bind]').forEach(input => {
    if (state.dirty.has(input.dataset.rxBind!)) return;
    const value = state.values[input.dataset.rxBind!];
    if (input instanceof HTMLInputElement && input.type === 'checkbox') input.checked = Boolean(value);
    else input.value = String(value ?? '');
  });
  if (focused?.isConnected && selection && selection[0] !== null) {
    focused.focus();
    focused.setSelectionRange(selection[0], selection[1]);
  }
  state.dialogs.sync();
  if (event.detail.xhr.status === 422 && job.action !== 'refresh') state.dialogs.focusError();
  const url = state.root.dataset.rxUrl;
  if (url && url.startsWith('/') && !url.startsWith('//')) {
    // Another component may have updated the URL while this request ran.
    const nextUrl = new URL(url, window.location.origin);
    const merged = new URL(window.location.href);
    const prefix = state.root.id.slice(3) + '_';
    [...merged.searchParams.keys()].filter(key => key.startsWith(prefix)).forEach(key => merged.searchParams.delete(key));
    nextUrl.searchParams.forEach((value, key) => { if (key.startsWith(prefix)) merged.searchParams.set(key, value); });
    const method = job.action === 'refresh' ? 'replaceState' : 'pushState';
    history[method](null, '', merged.pathname + merged.search + merged.hash);
  }
  event.detail.isError = false;
  state.root.dispatchEvent(new CustomEvent('flo-reactive:updated', { bubbles: true }));
}) as EventListener);

document.addEventListener('htmx:afterRequest', ((event: CustomEvent) => {
  const entry = requests.get(event.detail.xhr);
  if (!entry) return;
  const state = entry.runtime;
  state.busy = false;
  loading(state, false);
  state.dialogs.settled();
  const code = event.detail.xhr.status;
  if (![200, 422].includes(code)) {
    state.pending = undefined;
    const keys: Record<number, string> = { 401: 'session', 403: 'forbidden', 404: 'missing', 409: 'changed', 419: 'expired' };
    status(state, state.messages[keys[code] ?? 'failed']);
    state.root.dispatchEvent(new CustomEvent('flo-reactive:error', { bubbles: true, detail: { status: code } }));
    return;
  }
  if (state.pending) { const next = state.pending; state.pending = undefined; send(state, next); }
}) as EventListener);

/**
 * Keep-alive: a snapshot expires after its TTL (30 minutes by default). Re-sign
 * it in the background once half the TTL has passed, so a long editing session
 * does not lose its draft. Only the signature is renewed; nothing is re-rendered.
 */
async function renew(state: Runtime): Promise<void> {
  if (state.renewing || state.busy) return;
  state.renewing = true;
  const sent = state.snapshot;
  try {
    const response = await fetch(state.root.dataset.rxEndpoint!, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': state.token, Accept: 'application/json' },
      body: new URLSearchParams({ _snapshot: sent, _token: state.token, _action: RENEW, _query: window.location.search }),
    });
    if (response.ok) {
      const data: unknown = await response.json();
      const snapshot = data && typeof data === 'object' ? (data as { snapshot?: unknown }).snapshot : undefined;
      // A normal update may have finished meanwhile with newer state; keep that one.
      if (typeof snapshot === 'string' && state.snapshot === sent) { state.snapshot = snapshot; state.signedAt = Date.now(); }
    } else if ([401, 403, 409, 419].includes(response.status)) {
      const keys: Record<number, string> = { 401: 'session', 403: 'forbidden', 409: 'changed', 419: 'expired' };
      status(state, state.messages[keys[response.status]]);
      state.signedAt = Number.POSITIVE_INFINITY; // stop retrying; the next action reports it too
      state.root.dispatchEvent(new CustomEvent('flo-reactive:error', { bubbles: true, detail: { status: response.status } }));
    }
  } catch { /* offline: try again on the next tick */ }
  finally { state.renewing = false; }
}

function keepAlive(): void {
  const now = Date.now();
  components.forEach(state => { if (now - state.signedAt > state.ttl * 500) void renew(state); });
}
setInterval(keepAlive, 30000);
// Background tabs and sleeping laptops may skip timers; check when they return.
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') keepAlive(); });
window.addEventListener('online', keepAlive);

new MutationObserver(() => {
  components.forEach((state, id) => {
    if (!state.root.isConnected) {
      if (state.timer) clearTimeout(state.timer);
      state.dialogs.destroy();
      state.root.dispatchEvent(new CustomEvent('flo-reactive:destroyed'));
      components.delete(id);
    }
  });
  initialize();
}).observe(document.documentElement, { childList: true, subtree: true });

window.addEventListener('popstate', () => window.location.reload());
initialize();
// Preserve an application's Alpine instance. Consumers can opt out of bundled
// initialization; Reactive's morph adapter uses its own imported instance.
if (!(window as any).Alpine) { (window as any).Alpine = Alpine; Alpine.start(); }
