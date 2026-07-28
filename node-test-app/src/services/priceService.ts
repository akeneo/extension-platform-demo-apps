import { recordApiCall } from '../lib/apiCallTracker';

const priceApiUrl = process.env.PRICE_API_URL ?? '';

async function getPrice(): Promise<number | null> {
  if (!priceApiUrl) return null;
  try {
    recordApiCall();
    const response = await fetch(priceApiUrl, { signal: AbortSignal.timeout(3000) });
    if (!response.ok) return null;
    const data = (await response.json()) as { price?: unknown };
    return data.price !== undefined ? Number(data.price) : null;
  } catch {
    return null;
  }
}

// Fetches `count` prices concurrently instead of one blocking round-trip at a
// time — fetch() is async, so firing them all before awaiting overlaps the
// network latency instead of stacking it.
export function getPrices(count: number): Promise<Array<number | null>> {
  return Promise.all(Array.from({ length: count }, () => getPrice()));
}
