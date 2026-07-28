import { redisCache as redis } from './redis';
const redisCache = redis;

const PREFIX = 'cache:';
const TAG_PREFIX = 'cache:tag:';

export async function cacheGet(key: string): Promise<string | null> {
  return redisCache.get(PREFIX + key);
}

export async function cacheSet(key: string, value: string, ttlSeconds: number, tags: string[]): Promise<void> {
  const pipeline = redisCache.pipeline();
  pipeline.setex(PREFIX + key, ttlSeconds, value);
  for (const tag of tags) {
    pipeline.sadd(TAG_PREFIX + tag, PREFIX + key);
    pipeline.expire(TAG_PREFIX + tag, ttlSeconds * 2);
  }
  await pipeline.exec();
}

export async function cacheInvalidateTags(tags: string[]): Promise<void> {
  for (const tag of tags) {
    const keys = await redisCache.smembers(TAG_PREFIX + tag);
    const toDelete = [...keys, TAG_PREFIX + tag];
    if (toDelete.length > 0) {
      await redisCache.del(...toDelete);
    }
  }
}
