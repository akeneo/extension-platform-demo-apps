import { AsyncLocalStorage } from 'async_hooks';

// Counts outbound HTTP calls (Akeneo API + fake price/stock services) made
// while processing a single sync attempt. The worker processes up to 2
// messages concurrently (see syncWorker.ts's prefetch), so a plain module-
// level counter would race across them — AsyncLocalStorage scopes the count
// to whichever message's async call chain is currently running.
const storage = new AsyncLocalStorage<{ count: number }>();

// `context` is a plain object the caller keeps a reference to, so its final
// count can be read after `fn` settles whether it resolved or threw.
export function runWithApiCallTracking<T>(fn: () => Promise<T>, context: { count: number }): Promise<T> {
  return storage.run(context, fn);
}

export function recordApiCall(): void {
  const context = storage.getStore();
  if (context) context.count++;
}
