import htmx from 'htmx.org';
import Alpine from '@alpinejs/csp';
import morph from '@alpinejs/morph';
import { DialogManager } from './dialogs';
import '../css/reactive.css';

type Scalar = string | number | boolean;
type Job = { sequence: number; action: string; state: Record<string, Scalar> };
type Runtime = {
  root: HTMLElement; snapshot: string; token: string; values: Record<string, Scalar>;
  sequence: number; busy: boolean; pending?: Job; timer?: ReturnType<typeof setTimeout>;
  dialogs: DialogManager;
  activeAction: string;
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
    const dialogs = new DialogManager(element, () => state.busy, action => schedule(state, action, true, {}));
    state = {
      root: element, snapshot: element.dataset.rxSnapshot!, token: element.dataset.rxToken!,
      values: JSON.parse(element.dataset.rxState!), sequence: 0, busy: false, dialogs, activeAction: 'refresh',
    };
    components.set(element.id, state);
    dialogs.sync();
    element.dispatchEvent(new CustomEvent('flo-reactive:initialized', { bubbles: true }));
  });
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
  state.root.querySelectorAll<HTMLButtonElement>('[data-rx-action], [data-rx-dialog-close], form[data-rx-submit] button[type="submit"], form[data-rx-submit] button:not([type])').forEach(button => {
    if (busy) {
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
  if (state.busy) { state.pending = job; return; }
  state.busy = true;
  state.activeAction = job.action;
  status(state, '');
  loading(state, true);
  const values: Record<string, string> = {
    _snapshot: state.snapshot, _token: state.token, _action: job.action,
    _query: window.location.search, _sequence: String(job.sequence),
  };
  Object.entries(job.state).forEach(([key, value]) => {
    values[`state[${key}]`] = typeof value === 'boolean' ? (value ? '1' : '0') : String(value);
  });
  void htmx.ajax('POST', state.root.dataset.rxEndpoint, {
    source: state.root, target: state.root, swap: 'none', values,
    headers: { 'X-CSRF-TOKEN': state.token, 'X-RX-Sequence': String(job.sequence) },
  }).catch(() => { /* htmx:afterRequest reports transport failures */ });
}

function schedule(state: Runtime, action = 'refresh', immediate = false, values = state.values): void {
  if (state.timer) clearTimeout(state.timer);
  state.timer = undefined;
  const sequence = ++state.sequence;
  // Search updates should supersede an older page selection while waiting.
  const job: Job = { sequence, action, state: { ...values } };
  if (immediate) send(state, job);
  else state.timer = setTimeout(() => { state.timer = undefined; send(state, job); }, 300);
}

/** Refresh a mounted component through its normal signed/authorized endpoint. */
export function refresh(key: string): boolean {
  const state = components.get(`rx-${key}`);
  if (!state) return false;
  schedule(state, 'refresh', true);
  return true;
}

document.addEventListener('input', event => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement || input instanceof HTMLSelectElement)) return;
  const key = input.dataset.rxBind;
  const state = runtime(input);
  if (!key || !state || !(key in state.values)) return;
  state.values[key] = input instanceof HTMLInputElement && input.type === 'checkbox' ? input.checked : input.value;
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
    }
  }
  schedule(state);
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
  if (!state || state.busy) return;
  event.preventDefault();
  if (button.dataset.rxDialogTrigger) state.dialogs.remember(button.dataset.rxDialogTrigger, button);
  if (button.dataset.rxSet && button.dataset.rxSet in state.values) {
    state.values[button.dataset.rxSet] = button.dataset.rxValue ?? '';
  }
  schedule(state, button.dataset.rxAction, true);
});

document.addEventListener('submit', event => {
  if (!(event.target instanceof HTMLFormElement) || !event.target.hasAttribute('data-rx-submit')) return;
  const state = runtime(event.target);
  if (!state || state.busy) { event.preventDefault(); return; }
  event.preventDefault();
  // Read the submitted controls too: autofill and programmatic value changes
  // need not emit an input event. Only registered writable bindings are sent.
  event.target.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('[data-rx-bind]').forEach(input => {
    const key = input.dataset.rxBind!;
    if (key in state.values) {
      state.values[key] = input instanceof HTMLInputElement && input.type === 'checkbox' ? input.checked : input.value;
    }
  });
  schedule(state, event.target.dataset.rxSubmit || 'refresh', true);
});

document.addEventListener('htmx:beforeRequest', ((event: CustomEvent) => {
  const root = event.detail.elt as HTMLElement;
  const state = components.get(root.id);
  if (!state) return;
  requests.set(event.detail.xhr, { runtime: state, job: {
    sequence: Number(event.detail.requestConfig.headers['X-RX-Sequence']),
    action: event.detail.requestConfig.parameters._action, state: { ...state.values },
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
    status(state, 'Invalid component response. Reload the page.'); return;
  }
  state.snapshot = next.dataset.rxSnapshot!;
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
    updating: (element: HTMLElement, replacement: HTMLElement) => {
      // The native open attribute belongs to the dialog lifecycle, not morph.
      if (element instanceof HTMLDialogElement && element.open) replacement.setAttribute('open', '');
    },
  });
  state.values = JSON.parse(state.root.dataset.rxState!);
  state.root.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('[data-rx-bind]').forEach(input => {
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
    const messages: Record<number, string> = {
      401: 'Your session ended. Reload the page.', 403: 'You no longer have permission for this component.',
      419: 'This page expired. Reload the page.', 409: 'The component changed. Reload the page.',
      404: 'The record or action is no longer available. Close the dialog and refresh the page.',
    };
    status(state, messages[code] ?? 'The update failed. Check your connection and try again.');
    state.root.dispatchEvent(new CustomEvent('flo-reactive:error', { bubbles: true, detail: { status: code } }));
    return;
  }
  if (state.pending) { const next = state.pending; state.pending = undefined; send(state, next); }
}) as EventListener);

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
