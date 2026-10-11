// The poll while not live (canvas-core.md client/src/sync/poll.ts): ops?after= every poll_seconds (bd_settings, 3 by default); every 15 s
// while the tab is hidden. Slice 3's socket stops it when the relay is up and starts it again when the relay goes away.
import type { Catchup } from './catchup';

export class Poller {
  private timer: number | null = null;
  private running = false;
  failures = 0;

  constructor(private catchup: Catchup, private seconds: number, private onState: (ok: boolean) => void) {}

  start(): void {
    if (this.running) return;
    this.running = true;
    this.schedule(this.seconds * 1000);
  }

  stop(): void {
    this.running = false;
    if (this.timer !== null) window.clearTimeout(this.timer);
    this.timer = null;
  }

  /** Pull now (after a commit of mine or a focus), then keep the rhythm. */
  kick(): void {
    if (!this.running) return;
    if (this.timer !== null) window.clearTimeout(this.timer);
    this.schedule(0);
  }

  private schedule(ms: number): void {
    this.timer = window.setTimeout(async () => {
      this.timer = null;
      if (!this.running) return;
      try {
        await this.catchup.pull();
        this.failures = 0;
        this.onState(true);
      } catch {
        this.failures++;
        this.onState(false);
      }
      if (!this.running) return;
      const base = document.hidden ? 15000 : this.seconds * 1000;
      this.schedule(this.failures > 0 ? Math.min(30000, base * (1 + this.failures)) : base);
    }, ms);
  }
}
