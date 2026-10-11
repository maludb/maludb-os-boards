// Toasts (canvas-core: a refusal's sentence, "Not saved — check your connection", "You replaced Maria's edit — Undo") and the banner (the
// soft budget's warning, once a session; "Live updates paused" is slice 3's).
export interface ToastOptions { kind?: 'info' | 'danger' | 'warning'; action?: { label: string; run: () => void }; ms?: number; id?: string }

export class Toasts {
  private warned = new Set<string>();
  constructor(private box: HTMLElement | null, private banner: HTMLElement | null) {}

  show(message: string, opts: ToastOptions = {}): HTMLElement | null {
    if (!this.box) return null;
    const el = document.createElement('div');
    el.className = `board-toast board-toast-${opts.kind ?? 'info'}`;
    el.setAttribute('role', opts.kind === 'danger' ? 'alert' : 'status');
    if (opts.id) el.id = opts.id;
    const text = document.createElement('span');
    text.className = 'board-toast-text';
    text.textContent = message;
    el.appendChild(text);
    if (opts.action) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'board-toast-action';
      btn.textContent = opts.action.label;
      const run = opts.action.run;
      btn.addEventListener('click', () => { run(); el.remove(); });
      el.appendChild(btn);
    }
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'board-toast-close';
    close.setAttribute('aria-label', 'Dismiss');
    close.textContent = '×';
    close.addEventListener('click', () => el.remove());
    el.appendChild(close);
    this.box.appendChild(el);
    while (this.box.children.length > 4) this.box.firstElementChild?.remove();
    window.setTimeout(() => el.remove(), opts.ms ?? (opts.action ? 9000 : 6000));
    return el;
  }

  /** A question with answers (deleting a frame: "Delete its contents too?") — the canvas's own toast, never a modal; it stays until answered. */
  ask(message: string, answers: { label: string; id: string; run: () => void }[], id: string): void {
    if (!this.box) return;
    document.getElementById(id)?.remove();
    const el = document.createElement('div');
    el.className = 'board-toast board-toast-ask';
    el.id = id;
    el.setAttribute('role', 'alertdialog');
    const text = document.createElement('span');
    text.className = 'board-toast-text';
    text.textContent = message;
    el.appendChild(text);
    for (const a of answers) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'board-toast-action';
      btn.id = a.id;
      btn.textContent = a.label;
      btn.addEventListener('click', () => { el.remove(); a.run(); });
      el.appendChild(btn);
    }
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'board-toast-close';
    close.setAttribute('aria-label', 'Cancel');
    close.textContent = '×';
    close.addEventListener('click', () => el.remove());
    el.appendChild(close);
    this.box.appendChild(el);
  }

  /** A warning shown once a session (per text). */
  warnOnce(message: string): void {
    if (this.warned.has(message)) return;
    try {
      const k = 'boards-warned:' + message;
      if (sessionStorage.getItem(k)) { this.warned.add(message); return; }
      sessionStorage.setItem(k, '1');
    } catch { /* storage refused: once per page */ }
    this.warned.add(message);
    if (!this.banner) return;
    this.banner.textContent = message;
    this.banner.classList.remove('d-none');
    window.setTimeout(() => this.banner?.classList.add('d-none'), 12000);
  }
}
