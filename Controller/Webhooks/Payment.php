<?php

namespace PayMaya\Payment\Controller\Webhooks;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

class Payment extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
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
     * Exception to throw if CSRF validation fails (we return null to skip it)
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate for CSRF (we return true to bypass it for this webhook)
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Execute webhook action
     *
     * @return ResultInterface|ResponseInterface
     */
    public function execute()
    {
        $this->webhooks->lock();
        
        try {
            $this->webhooks->dispatchEvent('paymaya_payment_webhook_event');
        } finally {
            $this->webhooks->unlock();
        }

        /** @var \Magento\Framework\Controller\Result\Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode(200);
        $result->setContents('');

        return $result;
    }
}
