import { recordApiCall } from '../lib/apiCallTracker';

const stockApiUrl = process.env.STOCK_API_URL ?? 'https://main-bvxea6i-76safdhxbwlwa.eu-5.platformsh.site/stocks/random';

async function getStock(): Promise<number | null> {
  try {
    recordApiCall();
    const response = await fetch(stockApiUrl, { signal: AbortSignal.timeout(3000) });
    if (!response.ok) return null;
    const data = (await response.json()) as { stock?: unknown };
    return data.stock !== undefined ? Number(data.stock) : null;
  } catch {
    return null;
  }
}

// Fetches `count` stock levels concurrently instead of one blocking round-trip
// at a time — fetch() is async, so firing them all before awaiting overlaps
// the network latency instead of stacking it.
export function getStocks(count: number): Promise<Array<number | null>> {
  return Promise.all(Array.from({ length: count }, () => getStock()));
}
