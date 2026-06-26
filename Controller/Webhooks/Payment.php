<?php

namespace PayMaya\Payment\Controller\Webhooks;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\ResultFactory;

class Payment extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \PayMaya\Payment\Gateway\Webhooks
     */
    protected $webhooks;

    /**
     * Payment constructor.
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \PayMaya\Payment\Gateway\Webhooks $webhooks
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \PayMaya\Payment\Gateway\Webhooks $webhooks
    ) {
        parent::__construct($context);

        $this->webhooks = $webhooks;
    }

    /**
     * Execute webhook action
     *
     * @return ResultInterface|ResponseInterface
     */
    public function execute()
    {
        $this->webhooks->lock();
        $this->webhooks->dispatchEvent('paymaya_payment_webhook_event');
        $this->webhooks->unlock();

        /** @var \Magento\Framework\Controller\Result\Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode(200);
        $result->setContents('');

        return $result;
    }
}
