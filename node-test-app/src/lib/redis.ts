import Redis from 'ioredis';

const redisUrl = process.env.REDIS_URL ?? process.env.CACHE_URL ?? 'redis://localhost:6379';

export const redisCache = new Redis(redisUrl);
redisCache.on('error', (err) => console.error('[redis] connection error:', err.message));
