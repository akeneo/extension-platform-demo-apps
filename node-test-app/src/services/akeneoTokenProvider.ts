import { redisCache } from '../lib/redis';
import { recordApiCall } from '../lib/apiCallTracker';

const CACHE_KEY = 'akeneo_api_token';
const TOKEN_TTL = 3500;

export class AkeneoTokenProvider {
  private readonly baseUrl = (process.env.AKENEO_BASE_URL ?? '').replace(/\/$/, '');
  private readonly clientId = process.env.AKENEO_CLIENT_ID ?? '';
  private readonly clientSecret = process.env.AKENEO_CLIENT_SECRET ?? '';
  private readonly username = process.env.AKENEO_USERNAME ?? '';
  private readonly password = process.env.AKENEO_PASSWORD ?? '';

  async getToken(): Promise<string> {
    const cached = await redisCache.get(CACHE_KEY);
    if (cached) return cached;

    recordApiCall();
    const response = await fetch(`${this.baseUrl}/api/oauth/v1/token`, {
      method: 'POST',
      headers: {
        Authorization: 'Basic ' + Buffer.from(`${this.clientId}:${this.clientSecret}`).toString('base64'),
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ grant_type: 'password', username: this.username, password: this.password }),
    });

    if (!response.ok) {
      throw new Error(`Akeneo token request failed with HTTP ${response.status}: ${await response.text()}`);
    }

    const data = (await response.json()) as { access_token: string };
    await redisCache.setex(CACHE_KEY, TOKEN_TTL, data.access_token);
    return data.access_token;
  }

  async invalidateToken(): Promise<void> {
    await redisCache.del(CACHE_KEY);
  }
}

export const akeneoTokenProvider = new AkeneoTokenProvider();
