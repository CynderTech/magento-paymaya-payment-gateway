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
        $safePayment = \PayMaya\Payment\Gateway\Order::loggable($refNumber);
        $safeOrder = \PayMaya\Payment\Gateway\Order::loggable($orderId);

        $this->logger->info("[Handle Webhook] Payment {$safePayment} for order {$safeOrder}");

        $order = $this->orderHelper->loadOrderByIncrementId($orderId, $refNumber);

        if (!$order) {
            $this->logger->warning("[Handle Webhook] Ignored: order {$safeOrder} not found");
            return;
        }

        // Cheap gate so that orders that are already settled cost no call to Maya.
        if (!self::awaitsPayment($order->getState())) {
            $this->logger->debug("[Handle Webhook] Order {$safeOrder} is not awaiting payment (state {$order->getState()}).");
            return;
        }

        $status = $this->verifier->verify($order, $refNumber);

        if ($status === null) {
            return;
        }

        // The call to Maya is slow: an admin may have canceled the order or another request may have
        // settled it meanwhile. Decide on, and save, a freshly loaded order, never on the stale one.
        $verified = $order;
        $order = $this->orderHelper->loadOrderByIncrementId($orderId, $refNumber);

        if (!$order) {
            $this->logger->warning("[Handle Webhook] Ignored: order {$safeOrder} disappeared while verifying");
            return;
        }

        if ($order->getId() !== $verified->getId()) {
            $this->logger->warning("[Handle Webhook] Ignored: order {$safeOrder} is not the order that was verified");
            return;
        }

        // A canceled order stays eligible so that a genuine success after an earlier failed
        // attempt in the same checkout is not lost; it is only ever revived by a verified success.
        $state = $order->getState();
        if (!self::awaitsPayment($state)) {
            $this->logger->debug("[Handle Webhook] Order {$safeOrder} is no longer awaiting payment (state {$state}).");
            return;
        }

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
        } elseif (in_array($status, PaymentVerifier::FAILURE_STATUSES, true)) {
            $this->orderHelper->setAsFailed($order, $refNumber);
        }
    }

    private static function awaitsPayment($state)
    {
        return in_array($state, [MagentoOrder::STATE_NEW, MagentoOrder::STATE_PENDING_PAYMENT, MagentoOrder::STATE_CANCELED], true);
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
}
