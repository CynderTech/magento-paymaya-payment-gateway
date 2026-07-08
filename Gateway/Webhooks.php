<?php

namespace PayMaya\Payment\Gateway;

/**
 * Class Webhooks
 * Handles incoming PayMaya webhooks and concurrency locking.
 */
class Webhooks
{
    /**
     * Webhook event types
     */
    public const PAYMENT_SUCCESS = 'paymaya_payment_success_webhook';
    public const PAYMENT_FAILED = 'paymaya_payment_failed_webhook';

    /**
     * @var \Magento\Framework\App\CacheInterface
     */
    protected $cache;

    /**
     * @var \PayMaya\Payment\Logger\Logger
     */
    protected $logger;

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $eventManager;

    /**
     * Webhooks constructor.
     *
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\App\Request\Http $request
     * @param \PayMaya\Payment\Logger\Logger $logger
     */
    public function __construct(
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\App\Request\Http $request,
        \PayMaya\Payment\Logger\Logger $logger
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->request = $request;
        $this->eventManager = $eventManager;
    }

    /**
     * Dispatch webhook event
     *
     * @param string $eventType
     * @return void
     */
    public function dispatchEvent($eventType)
    {
        try {
            if ($this->request->getMethod() === 'GET') {
                $this->logger->info("Webhooks are working correctly!");
                return;
            }

            // Retrieve the request's body and parse it as JSON
            $body = $this->request->getContent();

            $this->logger->debug('[Handle Webhook] For ' . $eventType . ' with payload ' . $body);

            $payload = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
                throw new \InvalidArgumentException(
                    'Invalid or malformed JSON payload received: ' . json_last_error_msg()
                );
            }

            $this->eventManager->dispatch(
                $eventType,
                [
                    'data' => $payload
                ]
            );

            $this->logger->info("[Handle Webhook] 200 OK");
        } catch (\Exception $e) {
            $this->logger->error('[Handle Webhook] ' . $e->getMessage());
        }
    }

    /**
     * When multiple events arrive at the same time, lock the current process so that we don't get DB deadlocks.
     * * Works similar to a queuing system, but is real time rather than cron-based.
     *
     * @return void
     */
    public function lock()
    {
        $wait = 70; // seconds to wait for lock
        $sleep = 2; // poll every X seconds
        
        do {
            $lock = $this->cache->load("paymaya_payment_webhooks_lock");
            if ($lock) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                sleep($sleep);
                $wait -= $sleep;
            }
        } while ($lock && $wait > 0);

        $this->cache->save(1, "paymaya_payment_webhooks_lock", [], 60);
    }

    /**
     * Unlock the webhook processing
     *
     * @return void
     */
    public function unlock()
    {
        $this->cache->remove("paymaya_payment_webhooks_lock");
    }
}
