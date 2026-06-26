<?php

namespace PayMaya\Payment\Model;

use Magento\Store\Model\ScopeInterface;

/**
 * Class Config
 * Configuration model for PayMaya Payment
 */
class Config
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Config\Model\ResourceModel\Config
     */
    protected $resourceConfig;

    /**
     * @var \Magento\Framework\App\Config\Storage\WriterInterface
     */
    protected $configWriter;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    public static $moduleVersion = "1.1.4";

    /**
     * Config constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Config\Model\ResourceModel\Config $resourceConfig
     * @param \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Config\Model\ResourceModel\Config $resourceConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->resourceConfig = $resourceConfig;
        $this->configWriter = $configWriter;
        $this->logger = $logger;
    }

    /**
     * Check if payment method is enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        $enabled = ((bool)$this->getConfigData('active'));
        return $enabled;
    }

    /**
     * Get config data
     *
     * @param string $field
     * @param string|null $sectionKey
     * @param int|null $storeId
     * @return mixed
     */
    public function getConfigData($field, $sectionKey = null, $storeId = null)
    {
        $section = "";

        if ($sectionKey) {
            $section = "_$sectionKey";
        }

        $data = $this->scopeConfig->getValue(
            "payment/paymaya_payment$section/$field",
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $data;
    }

    /**
     * Set config data
     *
     * @param string $field
     * @param mixed $value
     * @param string|null $sectionKey
     * @return mixed
     */
    public function setConfigData($field, $value, $sectionKey = null)
    {
        $section = "";

        if ($sectionKey) {
            $section = "_$sectionKey";
        }

        $this->logger->info("Field {$field}");
        $this->logger->info("Value {$value}");

        $data = $this->configWriter->save(
            "payment/paymaya_payment$section/$field",
            $value
        );

        return $data;
    }
}
