<?php

namespace PayMaya\Payment\Model\Ui;

/**
 * Class ConfigProvider
 * Provides configuration data to the checkout UI for PayMaya.
 */
class ConfigProvider implements \Magento\Checkout\Model\ConfigProviderInterface
{
    /**
     * Payment method code
     */
    public const CODE = 'paymaya_payment';

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        return [
            // 'key' => 'value' pairs of configuration
        ];
    }
}
