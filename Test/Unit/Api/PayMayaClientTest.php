<?php

namespace PayMaya\Payment\Test\Unit\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PayMaya\Payment\Api\PayMayaClient;

class PayMayaClientTest extends TestCase
{
    const PAYMENT_ID = '35ea1192-575e-4472-91c8-19ce9dd3dc1e';

    private $mock;
    private $history = [];

    private function client(Response ...$responses)
    {
        $this->mock = new MockHandler($responses);
        $this->history = [];
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        // The real constructor reads config and decrypts the secret key; only the HTTP client matters here.
        $client = (new \ReflectionClass(PayMayaClient::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(PayMayaClient::class, 'client');
        $property->setValue($client, new Client(['handler' => $stack]));

        return $client;
    }

    public function testReturnsThePaymentObject()
    {
        $payment = $this->client(new Response(200, [], '{"id":"' . self::PAYMENT_ID . '","status":"PAYMENT_SUCCESS"}'))
            ->retrievePayment(self::PAYMENT_ID);

        $this->assertSame('PAYMENT_SUCCESS', $payment['status']);
    }

    public function testCallsTheRetrievePaymentEndpointWithATimeout()
    {
        $this->client(new Response(200, [], '{"id":"' . self::PAYMENT_ID . '"}'))->retrievePayment(self::PAYMENT_ID);

        $this->assertCount(1, $this->history);
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('/payments/v1/payments/' . self::PAYMENT_ID, $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(15, $this->history[0]['options']['timeout']);
    }

    public function testClientErrorsReachTheCallerSoTheVerifierCanTellFinalAnswersApart()
    {
        $this->expectException(ClientException::class);
        $this->client(new Response(404, [], '{"code":"PY0001"}'))->retrievePayment(self::PAYMENT_ID);
    }

    public function testServerErrorsReachTheCallerSoMayaRetries()
    {
        $this->expectException(ServerException::class);
        $this->client(new Response(500))->retrievePayment(self::PAYMENT_ID);
    }

    #[DataProvider('unexpectedBodies')]
    public function testThrowsSoMayaRetriesWhenTheBodyIsNotAJsonObject($body)
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->client(new Response(200, [], $body))->retrievePayment(self::PAYMENT_ID);
    }

    public static function unexpectedBodies(): array
    {
        return [
            'list of payments' => ['[{"id":"35ea1192-575e-4472-91c8-19ce9dd3dc1e"}]'],
            'empty list' => ['[]'],
            'empty object' => ['{}'],
            'numeric-key object decodes like a list' => ['{"0":"a"}'],
            'nested list' => ['[[1]]'],
            'scalar' => ['"text"'],
            'not json' => ['<html>gateway error</html>'],
            'empty body' => [''],
        ];
    }

    #[DataProvider('malformedIds')]
    public function testRefusesMalformedIdsWithoutCallingMaya($id)
    {
        // One response is queued: if the client called Maya it would be consumed.
        $client = $this->client(new Response(200, [], '{"id":"x"}'));

        try {
            $client->retrievePayment($id);
            $this->fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertCount(1, $this->mock, 'no request may be sent for a malformed id');
        }
    }

    public static function malformedIds(): array
    {
        return [
            'path traversal' => ['../../checkout/v1/webhooks'],
            'query string' => ['abc12345?x=1'],
            'too short' => ['abc'],
            'trailing newline' => ["abcdefgh\n"],
            'array' => [['x']],
            'null' => [null],
        ];
    }
}
