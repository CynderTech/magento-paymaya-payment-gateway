<?php

namespace PayMaya\Payment\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use PayMaya\Payment\Gateway\Order as OrderHelper;
use PayMaya\Payment\Gateway\PaymentVerifier;
use PayMaya\Payment\Logger\Logger;
use PayMaya\Payment\Observer\PaymentWebhookObserver;

class PaymentWebhookObserverTest extends TestCase
{
    private $orderHelper;
    private $verifier;
    private $observer;

    protected function setUp(): void
    {
        $this->orderHelper = $this->createMock(OrderHelper::class);
        $this->verifier = $this->createMock(PaymentVerifier::class);
        $this->observer = new PaymentWebhookObserver($this->orderHelper, $this->verifier, $this->createMock(Logger::class));
    }

    private function fire(array $body)
    {
        $this->observer->execute(new Observer(['data' => $body]));
    }

    private function pendingOrder($state = Order::STATE_NEW)
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn($state);
        $this->orderHelper->method('loadOrderByIncrementId')->willReturn($order);

        return $order;
    }

    private $forged = ['id' => 'x1234567', 'requestReferenceNumber' => '000000042', 'status' => 'PAYMENT_SUCCESS', 'amount' => 10];

    public function testForgedSuccessDoesNothingWhenMayaDoesNotConfirm()
    {
        $this->pendingOrder();
        $this->verifier->method('verify')->willReturn(null);
        $this->orderHelper->expects($this->never())->method('setAsPaid');
        $this->orderHelper->expects($this->never())->method('setAsFailed');
        $this->orderHelper->expects($this->never())->method('createTransaction');

        $this->fire($this->forged);
    }

    public function testBodyStatusIsIgnoredInFavourOfVerifiedStatus()
    {
        $order = $this->pendingOrder();
        $this->verifier->method('verify')->willReturn('PAYMENT_EXPIRED');
        $this->orderHelper->expects($this->never())->method('setAsPaid');
        $this->orderHelper->expects($this->once())->method('setAsFailed')->with($order, 'x1234567');

        $this->fire($this->forged);
    }

    public function testVerifiedSuccessMarksOrderPaid()
    {
        $order = $this->pendingOrder();
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->expects($this->once())->method('createTransaction')->with($order, 'x1234567');
        $this->orderHelper->expects($this->once())->method('setAsPaid')->with($order);

        $this->fire($this->forged);
    }

    public function testSkipsOrdersThatAreNotAwaitingPayment()
    {
        foreach ([Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED, Order::STATE_HOLDED] as $state) {
            $helper = $this->createMock(OrderHelper::class);
            $verifier = $this->createMock(PaymentVerifier::class);
            $order = $this->createMock(Order::class);
            $order->method('getState')->willReturn($state);
            $helper->method('loadOrderByIncrementId')->willReturn($order);
            $verifier->expects($this->never())->method('verify');
            $helper->expects($this->never())->method('setAsFailed');

            (new PaymentWebhookObserver($helper, $verifier, $this->createMock(Logger::class)))
                ->execute(new Observer(['data' => $this->forged]));
        }
    }

    public function testVerifiedSuccessRevivesOrderCanceledByAnEarlierFailedAttempt()
    {
        $order = $this->pendingOrder(Order::STATE_CANCELED);
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->expects($this->once())->method('createTransaction')->with($order, 'x1234567');
        $this->orderHelper->expects($this->once())->method('setAsPaid')->with($order);

        $this->fire($this->forged);
    }

    public function testCanceledOrderIsNotTouchedByFailureOrUnverifiedNotifications()
    {
        foreach ([null, 'PAYMENT_FAILED', 'PAYMENT_EXPIRED'] as $verified) {
            $helper = $this->createMock(OrderHelper::class);
            $verifier = $this->createMock(PaymentVerifier::class);
            $order = $this->createMock(Order::class);
            $order->method('getState')->willReturn(Order::STATE_CANCELED);
            $helper->method('loadOrderByIncrementId')->willReturn($order);
            $verifier->method('verify')->willReturn($verified);
            $helper->expects($this->never())->method('setAsPaid');
            $helper->expects($this->never())->method('setAsFailed');

            (new PaymentWebhookObserver($helper, $verifier, $this->createMock(Logger::class)))
                ->execute(new Observer(['data' => $this->forged]));
        }
    }

    public function testUnknownOrderIsIgnored()
    {
        $this->orderHelper->method('loadOrderByIncrementId')->willReturn(null);
        $this->verifier->expects($this->never())->method('verify');

        $this->fire($this->forged);
    }

    public function testPayloadWithoutIdsIsIgnored()
    {
        $this->orderHelper->expects($this->never())->method('loadOrderByIncrementId');

        $this->fire(['status' => 'PAYMENT_SUCCESS']);
        $this->fire(['id' => ['x'], 'requestReferenceNumber' => 5]);
    }
}
