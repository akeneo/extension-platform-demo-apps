import * as amqp from 'amqplib';
import { QUEUE_NAME, QueueMessage, MAX_RETRIES, assertTopology, publishRetry } from '../lib/queue';
import { db } from '../lib/db';
import * as syncService from '../services/productSyncService';
import { runWithApiCallTracking } from '../lib/apiCallTracker';

const rabbitmqUrl = process.env.RABBITMQ_URL ?? 'amqp://guest:guest@localhost:5672/';
const CONCURRENCY = 2;

async function processMessage(data: QueueMessage['data']): Promise<number> {
  switch (data.type) {
    case 'syncByUuid':
      return syncService.syncProductsByUuid(data.uuids);
    case 'syncByIdentifier':
      return syncService.syncProducts(data.identifiers);
    case 'syncByModelCode':
      return syncService.syncProductsByModelCodes(data.modelCodes);
    case 'productUpdated':
      return (await syncService.updateIfExists(data.identifier, data.isUuid)) ? 1 : 0;
  }
}

async function run(): Promise<void> {
  const connection = await amqp.connect(rabbitmqUrl);
  connection.on('error', err => console.error('[worker] connection error:', err.message));

  const channel = await connection.createChannel();
  await assertTopology(channel);
  await channel.prefetch(CONCURRENCY);

  channel.consume(QUEUE_NAME, async msg => {
    if (!msg) return;

    const { attemptId, data, retryCount } = JSON.parse(msg.content.toString()) as QueueMessage;

    await db.syncAttempt.update({
      where: { id: attemptId },
      data: { status: 'running', startedAt: new Date(), attempts: { increment: 1 } },
    }).catch(() => null);

    const apiCallContext = { count: 0 };

    try {
      const count = await runWithApiCallTracking(() => processMessage(data), apiCallContext);

      await db.syncAttempt.update({
        where: { id: attemptId },
        data: { status: 'completed', count, apiCalls: apiCallContext.count, finishedAt: new Date() },
      }).catch(() => null);

      channel.ack(msg);
      console.log(`[worker] attempt ${attemptId} (${data.type}) completed`);
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);

      if (retryCount < MAX_RETRIES) {
        await db.syncAttempt.update({
          where: { id: attemptId },
          data: { status: 'queued', error: message, apiCalls: apiCallContext.count },
        }).catch(() => null);

        await publishRetry(attemptId, data, retryCount);
        channel.ack(msg);
        console.error(`[worker] attempt ${attemptId} (${data.type}) failed (retry ${retryCount + 1}/${MAX_RETRIES}):`, message);
      } else {
        await db.syncAttempt.update({
          where: { id: attemptId },
          data: { status: 'failed', error: message, apiCalls: apiCallContext.count, finishedAt: new Date() },
        }).catch(() => null);

        channel.ack(msg);
        console.error(`[worker] attempt ${attemptId} (${data.type}) failed permanently after ${MAX_RETRIES} retries:`, message);
      }
    }
  }, { noAck: false });

  console.log(`[worker] Listening on queue "${QUEUE_NAME}"…`);
}

run().catch(e => {
  console.error('[worker] fatal error:', e);
  process.exit(1);
});
