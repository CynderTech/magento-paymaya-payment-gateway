<?php

namespace PayMaya\Payment\Test\Unit\Gateway;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PayMaya\Payment\Api\PayMayaClient;
use PayMaya\Payment\Gateway\PaymentVerifier;
use PayMaya\Payment\Logger\Logger;

class PaymentVerifierTest extends TestCase
{
    const PAYMENT_ID = '35ea1192-575e-4472-91c8-19ce9dd3dc1e';

    private $client;
    private $verifier;

    protected function setUp(): void
    {
        $this->client = $this->createMock(PayMayaClient::class);
        $this->verifier = new PaymentVerifier($this->client, $this->createMock(Logger::class));
    }

    private function order($method = 'paymaya_payment', $total = 1500.50, $currency = 'PHP', $checkoutIds = null)
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')->with('maya_checkout_ids')->willReturn($checkoutIds);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getTotalDue')->willReturn($total);
        $order->method('getOrderCurrencyCode')->willReturn($currency);

        return $order;
    }

    private function mayaPayment(array $override = [])
    {
        return array_merge([
            'id' => self::PAYMENT_ID,
            'status' => 'PAYMENT_SUCCESS',
            'amount' => '1500.50',
            'currency' => 'PHP',
            'requestReferenceNumber' => '000000042',
        ], $override);
    }

    public function testAcceptsMatchingSuccess()
    {
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment());
        $this->assertSame('PAYMENT_SUCCESS', $this->verifier->verify($this->order(), self::PAYMENT_ID));
    }

    public function testAcceptsNumericAmountAndFailureStatusesOfAStoredCheckout()
    {
        foreach (['PAYMENT_FAILED', 'PAYMENT_EXPIRED'] as $status) {
            $client = $this->createMock(PayMayaClient::class);
            $client->method('retrievePayment')->willReturn($this->mayaPayment(['status' => $status, 'amount' => 1500.5]));
            $verifier = new PaymentVerifier($client, $this->createMock(Logger::class));
            $order = $this->order('paymaya_payment', 1500.50, 'PHP', [self::PAYMENT_ID]);
            $this->assertSame($status, $verifier->verify($order, self::PAYMENT_ID));
        }
    }

    #[DataProvider('failureStatuses')]
    public function testFailureIsIgnoredWhenTheOrderHasNoStoredCheckoutIds($status)
    {
        // An order placed before upgrading, or one whose checkout ID could not be saved: a failure
        // notice must not cancel it, even when reference, amount and currency all match.
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment(['status' => $status]));
        foreach ([null, []] as $stored) {
            $this->assertNull($this->verifier->verify($this->order('paymaya_payment', 1500.50, 'PHP', $stored), self::PAYMENT_ID));
        }
    }

    #[DataProvider('rejectedPayments')]
    public function testRejects(array $override)
    {
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment($override));
        $this->assertNull($this->verifier->verify($this->order(), self::PAYMENT_ID));
    }

    public static function rejectedPayments(): array
    {
        return [
            'amount off by one centavo' => [['amount' => '1500.49']],
            'amount missing' => [['amount' => null]],
            'wrong currency' => [['currency' => 'USD']],
            'other order reference' => [['requestReferenceNumber' => '000000043']],
            'other payment id' => [['id' => 'aaaaaaaa-575e-4472-91c8-19ce9dd3dc1e']],
            'still pending' => [['status' => 'PENDING_PAYMENT']],
            'cancelled is not actionable' => [['status' => 'PAYMENT_CANCELLED']],
            'unknown status' => [['status' => 'SOMETHING_ELSE']],
        ];
    }

    public function testRejectsOrderNotPlacedWithMaya()
    {
        $this->client->expects($this->never())->method('retrievePayment');
        $this->assertNull($this->verifier->verify($this->order('checkmo'), self::PAYMENT_ID));
    }

    public function testRejectsMalformedPaymentId()
    {
        $this->client->method('retrievePayment')->willThrowException(new \InvalidArgumentException('bad'));
        $this->assertNull($this->verifier->verify($this->order(), '../../x'));
    }

    public function testRejectsWhenMayaDoesNotKnowThePayment()
    {
        $this->client->method('retrievePayment')->willThrowException(
            new ClientException('not found', new Request('GET', '/'), new Response(404))
        );
        $this->assertNull($this->verifier->verify($this->order(), self::PAYMENT_ID));
    }

    public function testAcceptsPaymentOfTheCheckoutCreatedForTheOrder()
    {
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment());
        $order = $this->order('paymaya_payment', 1500.50, 'PHP', [self::PAYMENT_ID]);
        $this->assertSame('PAYMENT_SUCCESS', $this->verifier->verify($order, self::PAYMENT_ID));
    }

    #[DataProvider('failureStatuses')]
    public function testFailureFromAnotherCheckoutIsIgnored($status)
    {
        // Same order number, amount and currency, but created through someone else's checkout.
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment(['status' => $status]));
        $order = $this->order('paymaya_payment', 1500.50, 'PHP', ['bbbbbbbb-575e-4472-91c8-19ce9dd3dc1e']);
        $this->assertNull($this->verifier->verify($order, self::PAYMENT_ID));
    }

    public static function failureStatuses(): array
    {
        return ['failed' => ['PAYMENT_FAILED'], 'expired' => ['PAYMENT_EXPIRED']];
    }

    public function testNonStringStoredCheckoutIdsNeverMatch()
    {
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment(['status' => 'PAYMENT_FAILED', 'id' => '123']));
        $order = $this->order('paymaya_payment', 1500.50, 'PHP', [123, null, ['123']]);
        $this->assertNull($this->verifier->verify($order, '123'));
    }

    public function testAcceptedSuccessFromAnotherCheckoutIsLoggedAsWarning()
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('accepting a confirmed payment'));
        $client = $this->createMock(PayMayaClient::class);
        $client->method('retrievePayment')->willReturn($this->mayaPayment());
        $order = $this->order('paymaya_payment', 1500.50, 'PHP', ['bbbbbbbb-575e-4472-91c8-19ce9dd3dc1e']);

        $this->assertSame('PAYMENT_SUCCESS', (new PaymentVerifier($client, $logger))->verify($order, self::PAYMENT_ID));
    }

    public function testConfirmedSuccessFromAnotherCheckoutIsStillAccepted()
    {
        // e.g. a payment method whose payment ID is not the checkout ID: never lose a real payment.
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment());
        $order = $this->order('paymaya_payment', 1500.50, 'PHP', ['bbbbbbbb-575e-4472-91c8-19ce9dd3dc1e']);
        $this->assertSame('PAYMENT_SUCCESS', $this->verifier->verify($order, self::PAYMENT_ID));
    }

    public function testSuccessFromAnotherCheckoutStillNeedsMatchingAmount()
    {
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment(['amount' => '1.00']));
        $order = $this->order('paymaya_payment', 1500.50, 'PHP', ['bbbbbbbb-575e-4472-91c8-19ce9dd3dc1e']);
        $this->assertNull($this->verifier->verify($order, self::PAYMENT_ID));
    }

    public function testAcceptsPaymentFromAnEarlierCheckoutOfTheSameOrder()
    {
        // The buyer reopened the payment page, so the order has two checkouts; the first one was paid.
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment());
        $order = $this->order('paymaya_payment', 1500.50, 'PHP', [self::PAYMENT_ID, 'cccccccc-575e-4472-91c8-19ce9dd3dc1e']);
        $this->assertSame('PAYMENT_SUCCESS', $this->verifier->verify($order, self::PAYMENT_ID));
    }

    public function testRememberCheckoutIdKeepsEveryCheckoutAndCapsTheList()
    {
        $store = [];
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(function ($key) use (&$store) {
            return $store[$key] ?? null;
        });
        $payment->method('setAdditionalInformation')->willReturnCallback(function ($key, $value) use (&$store, $payment) {
            $store[$key] = $value;
            return $payment;
        });

        PaymentVerifier::rememberCheckoutId($payment, 'first');
        PaymentVerifier::rememberCheckoutId($payment, 'second');
        PaymentVerifier::rememberCheckoutId($payment, 'first');   // same checkout again: no duplicate
        PaymentVerifier::rememberCheckoutId($payment, '');        // ignored
        PaymentVerifier::rememberCheckoutId($payment, null);      // ignored
        $this->assertSame(['second', 'first'], $store['maya_checkout_ids']);

        for ($i = 1; $i <= 12; $i++) {
            PaymentVerifier::rememberCheckoutId($payment, "id-$i");
        }
        $this->assertCount(PaymentVerifier::MAX_CHECKOUT_IDS, $store['maya_checkout_ids']);
        $this->assertSame('id-12', end($store['maya_checkout_ids']));
        $this->assertNotContains('first', $store['maya_checkout_ids']);
    }

    public function testOrdersWithoutStoredCheckoutIdStillUseTheOtherChecks()
    {
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment());
        $this->assertSame('PAYMENT_SUCCESS', $this->verifier->verify($this->order('paymaya_payment', 1500.50, 'PHP', []), self::PAYMENT_ID));
    }

    public function testRejectsUnprocessableRequest()
    {
        $this->client->method('retrievePayment')->willThrowException(
            new ClientException('bad', new Request('GET', '/'), new Response(422))
        );
        $this->assertNull($this->verifier->verify($this->order(), self::PAYMENT_ID));
    }

    #[DataProvider('retryableClientErrors')]
    public function testThrowsSoMayaRetriesOnAuthAndRateLimitErrors($code)
    {
        $this->client->method('retrievePayment')->willThrowException(
            new ClientException('nope', new Request('GET', '/'), new Response($code))
        );
        $this->expectException(\RuntimeException::class);
        $this->verifier->verify($this->order(), self::PAYMENT_ID);
    }

    public static function retryableClientErrors(): array
    {
        return ['bad key' => [401], 'forbidden' => [403], 'timeout' => [408], 'rate limited' => [429]];
    }

    public function testAcceptsAmountDeliveredAsObject()
    {
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment(['amount' => ['value' => '1500.50']]));
        $this->assertSame('PAYMENT_SUCCESS', $this->verifier->verify($this->order(), self::PAYMENT_ID));
    }

    public function testComparesAgainstAmountStillDue()
    {
        // A partially paid order: Maya's full amount no longer matches what is due.
        $this->client->method('retrievePayment')->willReturn($this->mayaPayment());
        $this->assertNull($this->verifier->verify($this->order('paymaya_payment', 1000.00), self::PAYMENT_ID));
    }

    public function testThrowsSoMayaRetriesWhenMayaAnswersWithSomethingUnexpected()
    {
        $this->client->method('retrievePayment')->willThrowException(new \UnexpectedValueException('not a JSON object'));
        $this->expectException(\RuntimeException::class);
        $this->verifier->verify($this->order(), self::PAYMENT_ID);
    }

    public function testThrowsSoMayaRetriesWhenApiIsUnreachable()
    {
        $this->client->method('retrievePayment')->willThrowException(
            new ConnectException('timeout', new Request('GET', '/'))
        );
        $this->expectException(\RuntimeException::class);
        $this->verifier->verify($this->order(), self::PAYMENT_ID);
    }
}
