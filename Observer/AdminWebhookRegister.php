<?php

namespace PayMaya\Payment\Observer;

use GuzzleHttp\Exception\ClientException;
use Magento\Framework\Event\ObserverInterface;

/**
 * Class AdminWebhookRegister
 * Observer to register PayMaya webhooks upon config save.
 */
class AdminWebhookRegister implements ObserverInterface
{
    /**
     * @var \PayMaya\Payment\Logger\Logger
     */
    protected $logger;

    /**
     * @var \PayMaya\Payment\Model\Config
     */
    protected $config;

    /**
     * @var \PayMaya\Payment\Api\PayMayaClient
     */
    protected $client;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var string[]
     */
    protected $overridable_webhooks;

    /**
     * AdminWebhookRegister constructor.
     *
     * @param \PayMaya\Payment\Logger\Logger $logger
     * @param \PayMaya\Payment\Model\Config $config
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \PayMaya\Payment\Api\PayMayaClient $client
     */
    public function __construct(
        \PayMaya\Payment\Logger\Logger $logger,
        \PayMaya\Payment\Model\Config $config,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \PayMaya\Payment\Api\PayMayaClient $client
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->client = $client;
        
        $this->overridable_webhooks = [
            'CHECKOUT_SUCCESS',
            'CHECKOUT_FAILURE',
            'PAYMENT_SUCCESS',
            'PAYMENT_FAILED',
            'PAYMENT_EXPIRED',
        ];
    }

    /**
     * Execute observer to register webhooks
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $mode = $this->config->getConfigData('paymaya_mode', 'basic');
        $encryptedSecretKey = $this->config->getConfigData("paymaya_{$mode}_sk", 'basic');

        if ($this->config->isEnabled() && isset($encryptedSecretKey)) {
            try {
                $body = $this->client->retrieveWebhooks();
                $webhooks = json_decode($body, true);
    
                foreach ($webhooks as $webhook) {
                    if (in_array($webhook['name'], $this->overridable_webhooks)) {
                        $this->client->deleteWebhook($webhook['id']);
                    }
                }
    
                $webhookBaseUrl = $this->config->getConfigData('webhook_base_url', 'webhooks');
    
                $this->client->createWebhook('CHECKOUT_SUCCESS', "{$webhookBaseUrl}/paymaya/webhooks");
                $this->client->createWebhook('CHECKOUT_FAILURE', "{$webhookBaseUrl}/paymaya/webhooks");
                $this->client->createWebhook('PAYMENT_SUCCESS', "{$webhookBaseUrl}/paymaya/webhooks/payment");
                $this->client->createWebhook('PAYMENT_FAILED', "{$webhookBaseUrl}/paymaya/webhooks/payment");
                $this->client->createWebhook('PAYMENT_EXPIRED', "{$webhookBaseUrl}/paymaya/webhooks/payment");
            } catch (ClientException $e) {
                $this->logger->error('[Register Webhooks] ' . $e->getResponse()->getBody()->__toString());
            }
        }
    }
}
