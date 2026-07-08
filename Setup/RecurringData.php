<?php

namespace PayMaya\Payment\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * Class RecurringData
 * * Note: Although this implements InstallDataInterface, Magento explicitly executes
 * Setup/RecurringData.php at the end of EVERY setup:upgrade run. It bypasses
 * the module data version check, making it safe for recurring data backfills.
 */
class RecurringData implements \Magento\Framework\Setup\InstallDataInterface
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
     * RecurringData constructor.
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
     * Install data hooks for the module
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
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
            
            $baseUrl = $rawBaseUrl !== '' ? rtrim($rawBaseUrl, '/') : '';

            $this->config->setConfigData('webhook_base_url', "{$baseUrl}", 'webhooks');
        }
    }
}
