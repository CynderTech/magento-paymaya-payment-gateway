<?php

namespace PayMaya\Payment\Observer;
use Magento\Sales\Model\Order as MagentoOrder;
use PayMaya\Payment\Gateway\PaymentVerifier;

class PaymentWebhookObserver implements \Magento\Framework\Event\ObserverInterface
{
    protected $logger;
    protected $orderHelper;
    protected $verifier;

    public function __construct(
        \PayMaya\Payment\Gateway\Order $orderHelper,
        \PayMaya\Payment\Gateway\PaymentVerifier $verifier,
        \PayMaya\Payment\Logger\Logger $logger
    ) {
        $this->logger = $logger;
        $this->orderHelper = $orderHelper;
        $this->verifier = $verifier;
    }

    /**
     * The webhook body is only a pointer: just the payment ID and the order reference are
     * read from it. The status, amount and currency all come from Maya's own API.
     */
    public function execute(\Magento\Framework\Event\Observer $observer) {
        $data = $observer->getData('data');

        $refNumber = is_array($data) && is_string($data['id'] ?? null) ? $data['id'] : null;
        $orderId = is_array($data) && is_string($data['requestReferenceNumber'] ?? null) ? $data['requestReferenceNumber'] : null;

        if ($refNumber === null || $orderId === null) {
            $this->logger->warning('[Handle Webhook] Ignored: payload has no payment ID or reference number');
            return;
        }

        // Both values come from an unauthenticated request: keep them log-safe.
        $safePayment = self::loggable($refNumber);
        $safeOrder = self::loggable($orderId);

        $this->logger->info("[Handle Webhook] Payment {$safePayment} for order {$safeOrder}");

        $order = $this->orderHelper->loadOrderByIncrementId($orderId);

        if (!$order) {
            $this->logger->warning("[Handle Webhook] Ignored: order {$safeOrder} not found");
            return;
        }

        // A canceled order stays eligible so that a genuine success after an earlier failed
        // attempt in the same checkout is not lost; it is only ever revived by a verified success.
        $state = $order->getState();
        if (!in_array($state, [MagentoOrder::STATE_NEW, MagentoOrder::STATE_PENDING_PAYMENT, MagentoOrder::STATE_CANCELED], true)) {
            $this->logger->debug("[Handle Webhook] Order {$safeOrder} is not awaiting payment (state {$state}).");
            return;
        }

        $status = $this->verifier->verify($order, $refNumber);

        if ($state === MagentoOrder::STATE_CANCELED) {
            if ($status !== PaymentVerifier::STATUS_SUCCESS) {
                return;
            }

            // setAsFailed only flips the state, so the items of such an order were never released and
            // paying it is consistent. An order canceled properly in Magento (items released, stock and
            // rules reverted) cannot be reopened safely: leave it for the merchant to fulfil or refund.
            if (self::hasReleasedItems($order)) {
                // Maya may deliver the same webhook several times: note it once.
                $note = "Maya confirmed payment {$refNumber} after this order was canceled and its items released. The order was not reopened: fulfil or refund it manually.";

                if ($this->orderHelper->hasComment($order, $note)) {
                    $this->logger->debug("[Handle Webhook] Order {$safeOrder} already flagged for payment {$safePayment}.");
                    return;
                }

                $this->logger->critical("[Handle Webhook] Order {$safeOrder} was canceled in Magento but Maya confirms payment {$safePayment}; not reopening it, reconcile or refund manually.");
                $this->orderHelper->addComment($order, $note);
                return;
            }

            $this->logger->warning("[Handle Webhook] Order {$safeOrder} was canceled but Maya confirms payment {$safePayment}; marking it paid.");
        }

        if ($status === PaymentVerifier::STATUS_SUCCESS) {
            $this->orderHelper->createTransaction($order, $refNumber);
            $this->orderHelper->setAsPaid($order);
        } else if ($status !== null) {
            $this->orderHelper->setAsFailed($order, $refNumber);
        }
    }

    private static function hasReleasedItems($order)
    {
        foreach ($order->getAllItems() as $item) {
            if ((float) $item->getQtyCanceled() > 0) {
                return true;
            }
        }

        return false;
    }

    private static function loggable($value)
    {
        return substr(preg_replace('/[^A-Za-z0-9._-]/', '?', $value), 0, 64);
    }
}
