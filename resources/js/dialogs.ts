type Entry = {
  dialog: HTMLDialogElement; opener?: HTMLElement; openerId?: string;
  dismissed: boolean; ignoredCloses: number; cancel: EventListener; close: EventListener; keydown: EventListener;
};

/** Native dialogs supply modal focus containment and background inertness. */
export class DialogManager {
  private entries = new Map<string, Entry>();
  private openers = new Map<string, HTMLElement>();

  constructor(private root: HTMLElement, private busy: () => boolean, private requestClose: (action: string) => void) {}

  remember(name: string, opener: HTMLElement): void {
    this.openers.set(name, opener);
    const entry = this.entries.get(name);
    if (entry) { entry.dismissed = false; entry.opener = opener; entry.openerId = opener.id; }
  }

  sync(): void {
    this.entries.forEach((entry, name) => {
      if (!entry.dialog.isConnected) {
        this.close(entry, name);
        this.detach(entry);
        this.entries.delete(name);
      }
    });
    this.root.querySelectorAll<HTMLDialogElement>('dialog[data-rx-dialog]').forEach(dialog => {
      const name = dialog.dataset.rxDialog!;
      let entry = this.entries.get(name);
      if (!entry || entry.dialog !== dialog) {
        if (entry) { this.close(entry, name); this.detach(entry); }
        const active = document.activeElement;
        const opener = this.openers.get(name) ?? (active instanceof HTMLElement ? active : undefined);
        const created: Entry = {
          dialog, opener, openerId: opener?.id, dismissed: false, ignoredCloses: 0,
          cancel: event => { event.preventDefault(); this.dismiss(name); },
          keydown: event => { if (event instanceof KeyboardEvent) this.trapTab(dialog, event); },
          close: () => {
            if (created.ignoredCloses > 0) { created.ignoredCloses--; return; }
            // Treat an application's direct dialog.close() as cancellation too.
            if (this.busy()) { this.sync(); return; }
            created.dismissed = true;
            this.restoreFocus(created);
            this.emit('closed', name);
            if (dialog.dataset.rxClose) this.requestClose(dialog.dataset.rxClose);
          },
        };
        entry = created;
        dialog.addEventListener('cancel', created.cancel);
        dialog.addEventListener('close', created.close);
        dialog.addEventListener('keydown', created.keydown);
        this.entries.set(name, entry);
      }
      if (dialog.dataset.rxOpen === 'true' && !entry.dismissed) {
        if (!dialog.open) { dialog.showModal(); this.emit('opened', name); }
      } else {
        this.close(entry, name);
        if (dialog.dataset.rxOpen !== 'true') {
          if (entry.dismissed) this.restoreFocus(entry);
          entry.dismissed = false;
        }
      }
    });
  }

  dismiss(name: string): void {
    const entry = this.entries.get(name);
    if (!entry || !entry.dialog.open || this.busy()) return;
    // Close locally first, so revoked permission/network failure cannot trap
    // the user. The explicit server action discards the signed editing state.
    entry.dismissed = true;
    this.close(entry, name);
    if (entry.dialog.dataset.rxClose) this.requestClose(entry.dialog.dataset.rxClose);
  }

  focusError(): void {
    this.entries.forEach(entry => {
      if (!entry.dialog.open) return;
      const invalid = entry.dialog.querySelector<HTMLElement>('[aria-invalid="true"]:not([disabled])')
        ?? entry.dialog.querySelector<HTMLElement>('[data-rx-error-summary]');
      invalid?.focus();
    });
  }

  settled(): void {
    // A local dismissal disables its opener while the cancel request runs.
    // Restore focus once that button is enabled again, including on failure.
    this.entries.forEach(entry => { if (entry.dismissed && !entry.dialog.open) this.restoreFocus(entry); });
  }

  focusBusy(): void {
    this.entries.forEach(entry => {
      if (entry.dialog.open && !entry.dialog.contains(document.activeElement)) {
        entry.dialog.tabIndex = -1;
        entry.dialog.focus();
      }
    });
  }

  destroy(): void {
    this.entries.forEach((entry, name) => { this.close(entry, name); this.detach(entry); });
    this.entries.clear();
    this.openers.clear();
  }

  private close(entry: Entry, name: string): void {
    if (!entry.dialog.open) return;
    entry.ignoredCloses++;
    entry.dialog.close();
    this.restoreFocus(entry);
    this.emit('closed', name);
  }

  private restoreFocus(entry: Entry): void {
    if (!this.root.isConnected) return;
    const opener = entry.opener?.isConnected ? entry.opener : (entry.openerId ? document.getElementById(entry.openerId) : null);
    if (opener && this.root.contains(opener) && !opener.hasAttribute('disabled')) { opener.focus(); return; }
    const fallback = this.root.querySelector<HTMLElement>('[data-rx-focus-fallback]');
    if (fallback) fallback.focus();
    else { this.root.tabIndex = -1; this.root.focus(); }
  }

  private detach(entry: Entry): void {
    entry.dialog.removeEventListener('cancel', entry.cancel);
    entry.dialog.removeEventListener('close', entry.close);
    entry.dialog.removeEventListener('keydown', entry.keydown);
  }

  private trapTab(dialog: HTMLDialogElement, event: KeyboardEvent): void {
    if (event.key !== 'Tab' || !dialog.open) return;
    const controls = [...dialog.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])')]
      .filter(control => control.getClientRects().length > 0);
    const first = controls[0];
    const last = controls.at(-1);
    if (!first) { event.preventDefault(); dialog.tabIndex = -1; dialog.focus(); return; }
    const active = document.activeElement;
    if (event.shiftKey && (active === first || active === dialog || !dialog.contains(active))) {
      event.preventDefault(); last!.focus();
    } else if (!event.shiftKey && (active === last || active === dialog || !dialog.contains(active))) {
      event.preventDefault(); first.focus();
    }
  }

  private emit(event: string, name: string): void {
    this.root.dispatchEvent(new CustomEvent(`flo-reactive:dialog-${event}`, { bubbles: true, detail: { name } }));
  }
}
