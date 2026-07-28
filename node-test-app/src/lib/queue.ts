import * as amqp from 'amqplib';

export const QUEUE_NAME = 'product-sync';

const rabbitmqUrl = process.env.RABBITMQ_URL ?? 'amqp://guest:guest@localhost:5672/';

// Matches Symfony Messenger's retry_strategy for this app: max_retries 3,
// delay 1000ms, multiplier 2 (1s, 2s, 4s). RabbitMQ has no built-in delayed
// retry, so each delay gets its own TTL queue that dead-letters back to the
// main queue once the message has waited out its backoff.
export const MAX_RETRIES = 3;
export const RETRY_DELAYS_MS = [1000, 2000, 4000];

export function retryQueueName(retryIndex: number): string {
  return `${QUEUE_NAME}.retry.${retryIndex}`;
}

export type SyncJobData =
  | { type: 'syncByUuid'; uuids: string[]; identifiers?: never; modelCodes?: never }
  | { type: 'syncByIdentifier'; identifiers: string[]; uuids?: never; modelCodes?: never }
  | { type: 'syncByModelCode'; modelCodes: string[]; uuids?: never; identifiers?: never }
  | { type: 'productUpdated'; identifier: string; isUuid: boolean; uuids?: never; identifiers?: never; modelCodes?: never };

export interface QueueMessage {
  attemptId: number;
  data: SyncJobData;
  retryCount: number;
}

export async function assertTopology(channel: amqp.Channel): Promise<void> {
  await channel.assertQueue(QUEUE_NAME, { durable: true });
  for (let i = 0; i < RETRY_DELAYS_MS.length; i++) {
    await channel.assertQueue(retryQueueName(i), {
      durable: true,
      messageTtl: RETRY_DELAYS_MS[i],
      deadLetterExchange: '',
      deadLetterRoutingKey: QUEUE_NAME,
    });
  }
}

let connectionPromise: Promise<amqp.ChannelModel> | null = null;
let channelPromise: Promise<amqp.ConfirmChannel> | null = null;

async function getChannel(): Promise<amqp.ConfirmChannel> {
  if (!channelPromise) {
    connectionPromise = amqp.connect(rabbitmqUrl);
    channelPromise = connectionPromise.then(async connection => {
      connection.on('error', (err: Error) => console.error('[queue] connection error:', err.message));
      connection.on('close', () => { channelPromise = null; connectionPromise = null; });
      const channel = await connection.createConfirmChannel();
      await assertTopology(channel);
      return channel;
    });
    channelPromise.catch(() => { channelPromise = null; connectionPromise = null; });
  }
  return channelPromise;
}

async function sendConfirmed(channel: amqp.ConfirmChannel, queue: string, message: QueueMessage): Promise<void> {
  await new Promise<void>((resolve, reject) => {
    channel.sendToQueue(queue, Buffer.from(JSON.stringify(message)), { persistent: true }, err => {
      if (err) reject(err); else resolve();
    });
  });
}

// Waits for the broker to confirm receipt, so callers that exit right after
// publishing (e.g. the daily-sync CLI) don't race a buffered-but-unsent write.
export async function publish(attemptId: number, data: SyncJobData): Promise<void> {
  const channel = await getChannel();
  await sendConfirmed(channel, QUEUE_NAME, { attemptId, data, retryCount: 0 });
}

// Re-queues a failed message into the TTL queue for its next backoff step.
// `currentRetryCount` is how many retries have already happened (0 on first
// failure); the message dead-letters back to the main queue once the TTL
// expires, carrying the incremented retry count.
export async function publishRetry(attemptId: number, data: SyncJobData, currentRetryCount: number): Promise<void> {
  const channel = await getChannel();
  await sendConfirmed(channel, retryQueueName(currentRetryCount), {
    attemptId,
    data,
    retryCount: currentRetryCount + 1,
  });
}

export async function closeConnection(): Promise<void> {
  const connection = await connectionPromise?.catch(() => null);
  await connection?.close().catch(() => {});
}
