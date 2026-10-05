<?php

namespace PayMaya\Payment\Test\Unit\Gateway;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\ManagerInterface;
use PHPUnit\Framework\TestCase;
use PayMaya\Payment\Gateway\Webhooks;
use PayMaya\Payment\Logger\Logger;

class WebhooksTest extends TestCase
{
    private function webhooks($body, ManagerInterface $events)
    {
        $request = $this->createMock(Http::class);
        $request->method('getContent')->willReturn($body);

        return new Webhooks($this->createMock(CacheInterface::class), $events, $request, $this->createMock(Logger::class));
    }

    public function testDispatchesPayloadAndAnswers200()
    {
        $events = $this->createMock(ManagerInterface::class);
        $events->expects($this->once())->method('dispatch')->with('some_event', ['data' => ['id' => 'abc']]);

        $this->assertSame(200, $this->webhooks('{"id":"abc"}', $events)->dispatchEvent('some_event'));
    }

    public function testAnswers400AndDispatchesNothingForABodyThatIsNotAJsonObject()
    {
        foreach (['', 'nope', '"text"', 'null', '123', '[]', '{}', '[{"id":"abc"}]', '["a","b"]', '{"0":"a"}'] as $body) {
            $events = $this->createMock(ManagerInterface::class);
            $events->expects($this->never())->method('dispatch');

            $this->assertSame(400, $this->webhooks($body, $events)->dispatchEvent('some_event'), "body: $body");
        }
    }

    public function testAcceptsObjectsWhateverTheirKeys()
    {
        foreach (['{"id":"abc"}', '{"1":"a"}', '{"id":"abc","list":[1,2]}'] as $body) {
            $events = $this->createMock(ManagerInterface::class);
            $events->expects($this->once())->method('dispatch');

            $this->assertSame(200, $this->webhooks($body, $events)->dispatchEvent('some_event'), "body: $body");
        }
    }

    public function testAnswers500SoMayaRetriesWhenAnObserverFails()
    {
        $events = $this->createMock(ManagerInterface::class);
        $events->method('dispatch')->willThrowException(new \RuntimeException('Maya unreachable'));

        $this->assertSame(500, $this->webhooks('{"id":"abc"}', $events)->dispatchEvent('some_event'));
    }
}
