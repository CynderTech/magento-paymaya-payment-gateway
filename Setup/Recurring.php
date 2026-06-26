<?php

namespace PayMaya\Payment\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Class Recurring
 * Executed after every module schema installation/upgrade to verify webhook configurations.
 */
class Recurring implements \Magento\Framework\Setup\InstallSchemaInterface
{
    /**
     * @var \PayMaya\Payment\Model\Config
     */
    protected $config;

    /**
     * @var \PayMaya\Payment\Logger\Logger
     */
    protected $logger;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * Recurring constructor.
     *
     * @param \PayMaya\Payment\Logger\Logger $logger
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \PayMaya\Payment\Model\Config $config
     */
    public function __construct(
        \PayMaya\Payment\Logger\Logger $logger,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \PayMaya\Payment\Model\Config $config
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
    }

    /**
     * Install data/schema hooks for the module
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $this->logger->debug('Checking webhook URL default values');

        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();

        $webhookBaseUrl = $this->config->getConfigData('webhook_base_url', 'webhooks', $store->getStoreId());

        $logWebhookBaseUrl = $webhookBaseUrl ?? '';
        $logBaseUrl = $store->getBaseUrl() ?? '';

        $this->logger->info("Webhook base URL is {$logWebhookBaseUrl}");
        $this->logger->info("Base URL is {$logBaseUrl}");

        if (empty($webhookBaseUrl)) {
            $rawBaseUrl = $store->getBaseUrl() ?? '';
            $baseUrl = $rawBaseUrl !== '' ? substr($rawBaseUrl, 0, -1) : '';

            $this->config->setConfigData('webhook_base_url', "{$baseUrl}", 'webhooks');
        }
    }
}
