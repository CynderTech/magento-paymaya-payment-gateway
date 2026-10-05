<?php

namespace PayMaya\Payment\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PayMaya\Payment\Gateway\Order as OrderHelper;
use PayMaya\Payment\Gateway\PaymentVerifier;
use PayMaya\Payment\Logger\Logger;
use PayMaya\Payment\Observer\PaymentWebhookObserver;

class PaymentWebhookObserverTest extends TestCase
{
    private $orderHelper;
    private $verifier;
    private $logger;
    private $observer;

    protected function setUp(): void
    {
        $this->orderHelper = $this->createMock(OrderHelper::class);
        $this->verifier = $this->createMock(PaymentVerifier::class);
        $this->logger = $this->createMock(Logger::class);
        $this->observer = new PaymentWebhookObserver($this->orderHelper, $this->verifier, $this->logger);
    }

    private function fire(array $body)
    {
        $this->observer->execute(new Observer(['data' => $body]));
    }

    private function pendingOrder($state = Order::STATE_NEW, array $items = [])
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn($state);
        $order->method('getAllItems')->willReturn($items);
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

    public function testOrderCanceledProperlyInMagentoIsNotReopenedButTheMerchantIsTold()
    {
        // qty_canceled > 0: Magento released the items, so paying the order would oversell.
        $released = $this->createStub(Item::class);
        $released->method('getQtyCanceled')->willReturn(1.0);
        $order = $this->pendingOrder(Order::STATE_CANCELED, [$released]);
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->method('hasComment')->willReturn(false);
        $this->orderHelper->expects($this->never())->method('setAsPaid');
        $this->orderHelper->expects($this->never())->method('createTransaction');
        $this->orderHelper->expects($this->once())->method('addComment')->with($order, $this->stringContains('x1234567'));
        $this->logger->expects($this->once())->method('critical');

        $this->fire($this->forged);
    }

    public function testRedeliveredWebhookDoesNotNoteOrAlertAgain()
    {
        $released = $this->createStub(Item::class);
        $released->method('getQtyCanceled')->willReturn(1.0);
        $this->pendingOrder(Order::STATE_CANCELED, [$released]);
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->method('hasComment')->willReturn(true);
        $this->orderHelper->expects($this->never())->method('addComment');
        $this->orderHelper->expects($this->never())->method('setAsPaid');
        $this->logger->expects($this->never())->method('critical');

        $this->fire($this->forged);
    }

    #[DataProvider('partlyReleasedItems')]
    public function testAnyReleasedItemKeepsTheOrderClosed(array $quantities)
    {
        $items = array_map(function ($qty) {
            $item = $this->createStub(Item::class);
            $item->method('getQtyCanceled')->willReturn($qty);
            return $item;
        }, $quantities);
        $this->pendingOrder(Order::STATE_CANCELED, $items);
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->method('hasComment')->willReturn(false);
        $this->orderHelper->expects($this->never())->method('setAsPaid');
        $this->orderHelper->expects($this->once())->method('addComment');

        $this->fire($this->forged);
    }

    public static function partlyReleasedItems(): array
    {
        return ['second item released' => [[0.0, 2.0]], 'string quantity' => [['1.0000']], 'parent and child' => [[1.0, 1.0]]];
    }

    public function testNullOrZeroQtyCanceledDoesNotCountAsReleased()
    {
        $items = [];
        foreach ([null, 0, '0.0000'] as $qty) {
            $item = $this->createStub(Item::class);
            $item->method('getQtyCanceled')->willReturn($qty);
            $items[] = $item;
        }
        $order = $this->pendingOrder(Order::STATE_CANCELED, $items);
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->expects($this->once())->method('setAsPaid')->with($order);

        $this->fire($this->forged);
    }

    public function testStateOnlyCancelWithUntouchedItemsIsStillRevivedByAVerifiedSuccess()
    {
        $untouched = $this->createStub(Item::class);
        $untouched->method('getQtyCanceled')->willReturn(0.0);
        $order = $this->pendingOrder(Order::STATE_CANCELED, [$untouched]);
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->expects($this->once())->method('setAsPaid')->with($order);
        $this->orderHelper->expects($this->never())->method('addComment');

        $this->fire($this->forged);
    }

    public function testCanceledOrderIsNotTouchedByFailureOrUnverifiedNotifications()
    {
        foreach ([null, 'PAYMENT_FAILED', 'PAYMENT_EXPIRED'] as $verified) {
            $helper = $this->createMock(OrderHelper::class);
            $verifier = $this->createMock(PaymentVerifier::class);
            $order = $this->createMock(Order::class);
            $order->method('getState')->willReturn(Order::STATE_CANCELED);
            $order->method('getAllItems')->willReturn([]);
            $helper->method('loadOrderByIncrementId')->willReturn($order);
            $verifier->method('verify')->willReturn($verified);
            $helper->expects($this->never())->method('setAsPaid');
            $helper->expects($this->never())->method('setAsFailed');

            (new PaymentWebhookObserver($helper, $verifier, $this->createMock(Logger::class)))
                ->execute(new Observer(['data' => $this->forged]));
        }
    }

    private function twoLoads($first, $second)
    {
        $this->orderHelper->method('loadOrderByIncrementId')->willReturnOnConsecutiveCalls($first, $second);
    }

    private function orderInState($state, array $items = [], $id = 7)
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getState')->willReturn($state);
        $order->method('getAllItems')->willReturn($items);

        return $order;
    }

    public function testSettledWhileVerifyingIsNotPaidAgain()
    {
        // The Maya call is slow: another request settled the order meanwhile.
        $this->twoLoads($this->orderInState(Order::STATE_NEW), $this->orderInState(Order::STATE_PROCESSING));
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->expects($this->never())->method('createTransaction');
        $this->orderHelper->expects($this->never())->method('setAsPaid');

        $this->fire($this->forged);
    }

    public function testCanceledByAnAdminWhileVerifyingIsNotReopened()
    {
        $released = $this->createStub(Item::class);
        $released->method('getQtyCanceled')->willReturn(1.0);
        $fresh = $this->orderInState(Order::STATE_CANCELED, [$released]);
        $this->twoLoads($this->orderInState(Order::STATE_NEW), $fresh);
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->method('hasComment')->willReturn(false);
        $this->orderHelper->expects($this->never())->method('setAsPaid');
        $this->orderHelper->expects($this->once())->method('addComment')->with($fresh, $this->anything());

        $this->fire($this->forged);
    }

    public function testTheFreshlyLoadedOrderIsTheOneThatIsSaved()
    {
        $stale = $this->orderInState(Order::STATE_NEW);
        $fresh = $this->orderInState(Order::STATE_NEW);
        $this->twoLoads($stale, $fresh);
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->expects($this->once())->method('createTransaction')->with($this->identicalTo($fresh), 'x1234567');
        $this->orderHelper->expects($this->once())->method('setAsPaid')->with($this->identicalTo($fresh));

        $this->fire($this->forged);
    }

    public function testADifferentOrderOnReloadIsNotActedOn()
    {
        // The lookup flipped to another entity with the same increment id: never save that one.
        $this->twoLoads($this->orderInState(Order::STATE_NEW, [], 7), $this->orderInState(Order::STATE_NEW, [], 8));
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->expects($this->never())->method('createTransaction');
        $this->orderHelper->expects($this->never())->method('setAsPaid');
        $this->orderHelper->expects($this->never())->method('setAsFailed');

        $this->fire($this->forged);
    }

    public function testAStatusThatIsNeitherSuccessNorFailureChangesNothing()
    {
        $this->pendingOrder();
        $this->verifier->method('verify')->willReturn('PAYMENT_CANCELLED');
        $this->orderHelper->expects($this->never())->method('setAsPaid');
        $this->orderHelper->expects($this->never())->method('setAsFailed');

        $this->fire($this->forged);
    }

    public function testOrderThatDisappearsWhileVerifyingIsIgnored()
    {
        $this->twoLoads($this->orderInState(Order::STATE_NEW), null);
        $this->verifier->method('verify')->willReturn('PAYMENT_SUCCESS');
        $this->orderHelper->expects($this->never())->method('setAsPaid');

        $this->fire($this->forged);
    }

    public function testNoSecondLoadWhenMayaDoesNotConfirm()
    {
        $this->pendingOrder();
        $this->verifier->method('verify')->willReturn(null);
        $this->orderHelper->expects($this->once())->method('loadOrderByIncrementId');

        $this->fire($this->forged);
    }

    public function testLoadsTheOrderWithThePaymentIdSoCollidingIncrementIdsCanBeTold()
    {
        $this->orderHelper->expects($this->once())->method('loadOrderByIncrementId')->with('000000042', 'x1234567')->willReturn(null);

        $this->fire($this->forged);
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
