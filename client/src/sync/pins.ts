// The pins' read (comments-notifications.md divergence 2): comments are not ops, so the relay carries none — the canvas re-reads the pins
// (GET /boards/{board}/comments?format=pins, JSON) every 15 s while the tab is visible, at once after the person's own comment action
// (the `commentChanged` event the handlers send over HX-Trigger), and when a tab comes back. "Show resolved" asks for the resolved ones too.
import type { PinLayer, Pin } from '../render/pins';

export class PinSync {
  private timer: number | null = null;
  private resolved = false;
  private busy = false;
  private again = false;
  private onChanged = () => { void this.refresh(); };
  private onVisible = () => { if (!document.hidden) void this.refresh(); };
  private onToggle = (e: Event) => {
    const t = e.target as HTMLInputElement | null;
    if (t && t.id === 'comment-rail-resolved') { this.resolved = t.checked; void this.refresh(); }
  };

  constructor(private url: string, private layer: PinLayer, private every = 15000) {}

  start(): void {
    void this.refresh();
    this.timer = window.setInterval(() => { if (!document.hidden) void this.refresh(); }, this.every);
    document.body.addEventListener('commentChanged', this.onChanged);
    document.addEventListener('visibilitychange', this.onVisible);
    document.body.addEventListener('change', this.onToggle);
  }

  stop(): void {
    if (this.timer !== null) window.clearInterval(this.timer);
    this.timer = null;
    document.body.removeEventListener('commentChanged', this.onChanged);
    document.removeEventListener('visibilitychange', this.onVisible);
    document.body.removeEventListener('change', this.onToggle);
  }

  /** Read the pins now; a read asked for while one is in flight runs once more afterwards. */
  async refresh(withResolved = this.resolved): Promise<Pin[]> {
    if (this.busy) { this.again = true; return []; }
    this.busy = true;
    try {
      const res = await fetch(`${this.url}?format=pins&resolved=${withResolved ? 1 : 0}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!res.ok) return [];
      const j = await res.json() as { data?: { pins?: Pin[] } };
      const pins = j.data?.pins ?? [];
      this.layer.set(pins);
      return pins;
    } catch {
      return [];
    } finally {
      this.busy = false;
      if (this.again) { this.again = false; void this.refresh(); }
    }
  }
}
