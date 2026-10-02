<?php

namespace PayMaya\Payment\Controller\Webhooks;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

class Index extends \Magento\Framework\App\Action\Action implements
    \Magento\Framework\App\Action\HttpPostActionInterface,
    CsrfAwareActionInterface
{
    protected $webhooks;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \PayMaya\Payment\Gateway\Webhooks $webhooks
    ) {
        parent::__construct($context);

        $this->webhooks = $webhooks;
    }

    public function execute()
    {
        $this->webhooks->lock();

        try {
            $status = $this->webhooks->dispatchEvent('paymaya_webhook_event');
        } finally {
            $this->webhooks->unlock();
        }

        /** @var \Magento\Framework\Controller\Result\Raw $result */
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode($status);

        return $result;
    }

    // Maya cannot send a form key. Authenticity comes from re-querying Maya, not from the request.
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
