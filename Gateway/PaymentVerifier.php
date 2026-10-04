<?php

namespace PayMaya\Payment\Gateway;

use GuzzleHttp\Exception\ClientException;
use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Sales\Model\Order\Payment;

/**
 * Decides whether a webhook notification is genuine by asking Maya directly.
 * Nothing in the webhook body is trusted except the payment ID used for the lookup.
 */
class PaymentVerifier
{
    const METHOD_CODE = 'paymaya_payment';
    const STATUS_SUCCESS = 'PAYMENT_SUCCESS';
    const CHECKOUT_IDS_KEY = 'maya_checkout_ids';
    const MAX_CHECKOUT_IDS = 10;

    // PAYMENT_CANCELLED (buyer walked away) is deliberately not actionable: the order stays pending.
    const FAILURE_STATUSES = ['PAYMENT_FAILED', 'PAYMENT_EXPIRED'];
    const ACTIONABLE_STATUSES = ['PAYMENT_SUCCESS', 'PAYMENT_FAILED', 'PAYMENT_EXPIRED'];

    protected $client;
    protected $logger;

    public function __construct(
        \PayMaya\Payment\Api\PayMayaClient $client,
        \PayMaya\Payment\Logger\Logger $logger
    ) {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * @return string|null The verified Maya status (one of ACTIONABLE_STATUSES), or null
     *                     when the notification must not change the order.
     * @throws \RuntimeException When Maya could not be reached or answered with a server
     *                           error, so the webhook can be retried later.
     */
    public function verify(MagentoOrder $order, $paymentId)
    {
        $incrementId = (string) $order->getIncrementId();

        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== self::METHOD_CODE) {
            return $this->reject($incrementId, 'order was not placed with Maya');
        }

        try {
            $maya = $this->client->retrievePayment($paymentId);
        } catch (\InvalidArgumentException $e) {
            return $this->reject($incrementId, 'malformed payment ID');
        } catch (ClientException $e) {
            $code = $e->getResponse()->getStatusCode();

            // Only "no such payment" answers are final. Anything else (401/403 wrong or rotated
            // key or mode, 408, 429) must not drop a genuine payment, so let Maya retry.
            if (in_array($code, [400, 404, 422], true)) {
                return $this->reject($incrementId, "Maya answered {$code} for the payment");
            }

            $this->logger->error("[Verify Webhook] Order {$incrementId}: Maya answered {$code}; check the secret key and mode");
            throw new \RuntimeException("Maya answered {$code} while retrieving the payment", 0, $e);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not retrieve Maya payment: ' . $e->getMessage(), 0, $e);
        }

        if (($maya['id'] ?? null) !== $paymentId) {
            return $this->reject($incrementId, 'payment ID mismatch');
        }

        if ((string) ($maya['requestReferenceNumber'] ?? '') !== $incrementId) {
            return $this->reject($incrementId, 'reference number mismatch');
        }

        $status = $maya['status'] ?? null;
        if (!in_array($status, self::ACTIONABLE_STATUSES, true)) {
            return $this->reject($incrementId, 'status ' . json_encode($status) . ' is not actionable');
        }

        // Anyone can create a checkout with our public key and any reference number,
        // so the amount and currency must match for every status, not only for success.
        $expectedCents = self::toCents($order->getTotalDue());
        $actualCents = self::toCents($maya['amount'] ?? null);
        if ($actualCents === null || $actualCents !== $expectedCents) {
            return $this->reject($incrementId, "amount mismatch (order {$expectedCents} cents, Maya "
                . self::show($maya['amount'] ?? null) . ')');
        }

        if (strtoupper((string) ($maya['currency'] ?? '')) !== strtoupper((string) $order->getOrderCurrencyCode())) {
            return $this->reject($incrementId, 'currency mismatch (order ' . $order->getOrderCurrencyCode()
                . ', Maya ' . self::show($maya['currency'] ?? null) . ')');
        }

        // For card checkouts the payment ID is the ID of a checkout we created (an order can have
        // several, as the buyer may reopen the payment page). A payment from some other checkout,
        // for example one made with our public key, must not cancel the order, and neither must a
        // notice we cannot tie to a checkout at all (an order placed before the IDs were stored, or
        // storing them failed): leaving an order pending is safe, canceling on an unverifiable
        // notice is not. A confirmed, fully matching success is always accepted: it is money
        // received for this very order, and the binding is not verified for every payment method.
        $checkoutIds = self::checkoutIds($payment);
        if (!in_array($paymentId, $checkoutIds, true)) {
            if ($status !== self::STATUS_SUCCESS) {
                return $this->reject($incrementId, 'failure notice for a payment that is not from a checkout created for this order');
            }

            $this->logger->warning("[Verify Webhook] Order {$incrementId}: accepting a confirmed payment that is not from a checkout stored for this order");
        }

        return $status;
    }

    /**
     * Remember a checkout created for the order. Keeps the newest MAX_CHECKOUT_IDS, so reopening the
     * payment page never invalidates a payment made through an earlier checkout.
     */
    public static function rememberCheckoutId(Payment $payment, $checkoutId)
    {
        if (!is_string($checkoutId) || $checkoutId === '') {
            return;
        }

        $ids = array_values(array_diff(self::checkoutIds($payment), [$checkoutId]));
        $ids[] = $checkoutId;

        $payment->setAdditionalInformation(self::CHECKOUT_IDS_KEY, array_slice($ids, -self::MAX_CHECKOUT_IDS));
    }

    public static function checkoutIds(Payment $payment)
    {
        $ids = $payment->getAdditionalInformation(self::CHECKOUT_IDS_KEY);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    private function reject($incrementId, $reason)
    {
        $this->logger->warning("[Verify Webhook] Order {$incrementId} not updated: {$reason}");
        return null;
    }

    private static function show($value)
    {
        return substr((string) json_encode($value), 0, 40);
    }

    private static function toCents($value)
    {
        // Tolerate an amount object such as {"value": "105.00"}
        if (is_array($value)) {
            $value = $value['value'] ?? ($value['amount'] ?? null);
        }

        return is_numeric($value) ? (int) round(((float) $value) * 100) : null;
    }
}
