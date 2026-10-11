// The relay (live-relay.md "The client side") — SLICE 3's. Slice 2 is polling only (canvas.md divergence 1): this is the interface the
// rest of the island calls, answering "unavailable", so main.ts runs the poll. Slice 3 replaces the body and keeps the shape: connect()
// with the mount's ticket and URLs, `state` and the callbacks, close().
import type { RemoteBatch } from '../model/types';

export type LiveState = 'live' | 'polling' | 'offline' | 'unavailable';

export interface LiveCallbacks {
  onBatch(b: RemoteBatch): void;
  onState(state: LiveState, peers: number): void;
  onResync(): void;
  onLevel(level: string): void;
}

export interface LiveConnection {
  readonly state: LiveState;
  close(): void;
}

export function connectLive(_cfg: { liveUrl: string; ticket: string | null; ticketUrl: string; csrf: string }, cb: LiveCallbacks): LiveConnection {
  cb.onState('unavailable', 0);
  return { state: 'unavailable', close: () => undefined };
}
