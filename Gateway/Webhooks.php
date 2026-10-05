<?php

namespace PayMaya\Payment\Gateway;

class Webhooks
{
    public const PAYMENT_SUCCESS = 'paymaya_payment_success_webhook';
    public const PAYMENT_FAILED = 'paymaya_payment_failed_webhook';

    protected $cache;
    protected $logger;
    protected $request;
    protected $eventManager;

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
     * @return int HTTP status for Maya: 200 handled or ignored, 400 unreadable body,
     *             500 on an internal error so that Maya retries later
     */
    public function dispatchEvent($eventType)
    {
        try
        {
            // Retrieve the request's body and parse it as JSON
            $payload = json_decode($this->request->getContent(), true);

            if (!self::isJsonObject($payload))
            {
                $this->logger->warning('[Handle Webhook] Rejected ' . $eventType . ': body is not a JSON object');
                return 400;
            }

            $this->eventManager->dispatch(
                $eventType,
                array(
                    'data' => $payload
                )
            );

            $this->logger->info("[Handle Webhook] 200 OK");
            return 200;
        }
        catch (\Exception $e)
        {
            $this->logger->error('[Handle Webhook] ' . $e->getMessage());
            return 500;
        }
    }

    // A decoded JSON object is an associative array; a list such as [{"id":"x"}] is not an event
    private static function isJsonObject($payload)
    {
        if (!is_array($payload) || $payload === []) {
            return false;
        }

        $expected = 0;
        foreach ($payload as $key => $_) {
            if ($key !== $expected++) {
                return true;
            }
        }

        return false;
    }

    // When multiple events arrive at the same time, lock the current process so that we don't get DB deadlocks
    // Works similar to a queuing system, but is real time rather than cron-based
    public function lock()
    {
        $wait = 70; // seconds to wait for lock
        $sleep = 2; // poll every X seconds
        do
        {
            $lock = $this->cache->load("paymaya_payment_webhooks_lock");
            if ($lock)
            {
                sleep($sleep);
                $wait -= $sleep;
            }

        } while ($lock && $wait > 0);

        $this->cache->save(1, "paymaya_payment_webhooks_lock", array(), $lifetime = 60);
    }

    public function unlock()
    {
        $this->cache->remove("paymaya_payment_webhooks_lock");
    }
}
