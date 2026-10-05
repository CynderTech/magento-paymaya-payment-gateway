<?php

namespace PayMaya\Payment\Observer;

/**
 * Class WebhooksObserver
 * Basic webhook observer passthrough process.
 */
class WebhooksObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \PayMaya\Payment\Logger\Logger
     */
    protected $logger;

    /**
     * @var \PayMaya\Payment\Gateway\Order
     */
    protected $orderHelper;

    /**
     * WebhooksObserver constructor.
     *
     * @param \PayMaya\Payment\Gateway\Order $orderHelper
     * @param \PayMaya\Payment\Logger\Logger $logger
     */
    public function __construct(
        \PayMaya\Payment\Gateway\Order $orderHelper,
        \PayMaya\Payment\Logger\Logger $logger
    ) {
        $this->logger = $logger;
        $this->orderHelper = $orderHelper;
    }

    /**
     * Execute generic webhook event passthrough
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->logger->info("Passthrough");
    }
}
