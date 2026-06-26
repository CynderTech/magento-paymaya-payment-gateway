<?php

namespace PayMaya\Payment\Api;

use GuzzleHttp\Client as GC;
use GuzzleHttp\Exception\ClientException;

/**
 * Class PayMayaClient
 * Handles API communication with PayMaya endpoints.
 */
class PayMayaClient
{
    /**
     * Sandbox API URL
     */
    public const SANDBOX_BASE_URL = 'https://pg-sandbox.paymaya.com';

    /**
     * Production API URL
     */
    public const PRODUCTION_BASE_URL = 'https://pg.paymaya.com';

    /**
     * @var GC
     */
    protected $client;

    /**
     * @var \PayMaya\Payment\Model\Config
     */
    protected $config;

    /**
     * @var \PayMaya\Payment\Logger\Logger
     */
    protected $logger;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * PayMayaClient constructor.
     *
     * @param \PayMaya\Payment\Model\Config $config
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \PayMaya\Payment\Logger\Logger $logger
     */
    public function __construct(
        \PayMaya\Payment\Model\Config $config,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \PayMaya\Payment\Logger\Logger $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->storeManager = $storeManager;

        $mode = $config->getConfigData('paymaya_mode', 'basic');
        $encryptedSecretKey = $config->getConfigData("paymaya_{$mode}_sk", 'basic');
        $secretKey = $encryptor->decrypt($encryptedSecretKey);
        $moduleVersion = $config::$moduleVersion;

        $defaultOptions['base_uri'] = $mode === 'test' ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
        $defaultOptions['headers']['authorization'] = $this->getAuthHeader($secretKey);
        $defaultOptions['headers']['x-paymaya-sdk'] = 'magento-v' . $moduleVersion;

        $client = new GC($defaultOptions);

        $this->client = $client;
    }

    /**
     * Retrieve registered webhooks
     *
     * @return \Psr\Http\Message\StreamInterface|string
     * @throws ClientException
     */
    public function retrieveWebhooks()
    {
        try {
            $response = $this->client->get('/checkout/v1/webhooks');
            return $response->getBody();
        } catch (ClientException $err) {
            $response = $err->getResponse();
            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                return "[]";
            }

            throw $err;
        }
    }

    /**
     * Delete a specific webhook by ID
     *
     * @param string $id
     * @return \Psr\Http\Message\StreamInterface
     */
    public function deleteWebhook($id)
    {
        $response = $this->client->delete("/checkout/v1/webhooks/{$id}");
        return $response->getBody();
    }

    /**
     * Create a new webhook
     *
     * @param string $type
     * @param string $url
     * @return \Psr\Http\Message\StreamInterface
     */
    public function createWebhook($type, $url)
    {
        $response = $this->client->post('/checkout/v1/webhooks', [
            'json' => [
                'name' => $type,
                'callbackUrl' => $url
            ],
        ]);

        return $response->getBody();
    }

    /**
     * Create a checkout session
     *
     * @param \Magento\Sales\Model\Order $order
     * @return \Psr\Http\Message\StreamInterface
     */
    public function createCheckout($order)
    {
        $mode = $this->config->getConfigData('paymaya_mode', 'basic');
        $publicKey = $this->config->getConfigData("paymaya_{$mode}_pk", 'basic');

        $payload = $this->formatOrderForPayment($order);

        $this->logger->debug('[Create Checkout][Payload]' . json_encode($payload));

        $response = $this->client->post('/checkout/v1/checkouts', [
            'json' => $payload,
            'headers' => [
                'authorization' => $this->getAuthHeader($publicKey)
            ]
        ]);

        return $response->getBody();
    }

    /**
     * Get authorization header string
     *
     * @param string $secretKey
     * @return string
     */
    private function getAuthHeader($secretKey)
    {
        return "Basic " . base64_encode($secretKey . ':');
    }

    /**
     * Format birthdate for PayMaya API
     *
     * @param string|null $rawBirthDate
     * @return string
     */
    private function formatBirthdate($rawBirthDate)
    {
        if (!isset($rawBirthDate)) {
            return '';
        }

        $time = strtotime($rawBirthDate);
        return date('Y-m-d', $time);
    }

    /**
     * Format gender for PayMaya API
     *
     * @param int|string $rawGender
     * @return string
     */
    private function formatGender($rawGender)
    {
        switch ($rawGender) {
            // Mapping out Unspecified option in Magento to Male in Maya by default
            case 0:
            case 1:
                return 'M';
            case 2:
                return 'F';
            default:
                return 'M';
        }
    }

    /**
     * Format Magento order into PayMaya payload
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    private function formatOrderForPayment(\Magento\Sales\Model\Order $order)
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();

        $orderItems = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $orderItems[] = [
                "name" => $item->getName(),
                "quantity" => $item->getQtyOrdered(),
                "description" => empty($item->getDescription()) ? $item->getName() : $item->getDescription(),
                "code" => $item->getSku(),
                "amount" => [
                    "value" => $item->getPrice()
                ],
                "totalAmount" => [
                    "value" => $item->getQtyOrdered() * $item->getPrice()
                ]
            ];
        }

        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();

        $addressGetter = isset($shippingAddress) ? $shippingAddress : $billingAddress;
        $rawBirthDate = $order->getCustomerDob();
        $rawGender = $order->getCustomerGender();

        $buyerData = [
            "firstName" => $order->getCustomerFirstname(),
            "middleName" => $order->getCustomerMiddlename(),
            "lastName" => $order->getCustomerLastname(),
            "birthday"=> $this->formatBirthdate($rawBirthDate),
            "sex" => $this->formatGender($rawGender),
            "contact" => [
                "phone" => $addressGetter->getTelephone(),
                "email" => $order->getCustomerEmail()
            ],
            "shippingAddress" => [
                "firstName" => $order->getCustomerFirstname(),
                "middleName" => $order->getCustomerMiddlename(),
                "lastName" => $order->getCustomerLastname(),
                "phone" => $addressGetter->getTelephone(),
                "email" => $order->getCustomerEmail(),
                "line1" => $addressGetter->getStreet(1)[0],
                "line2" => $addressGetter->getStreet(2)[0] ?? '',
                "city" => $addressGetter->getCity(),
                "state" => $addressGetter->getRegionCode(),
                "zipCode" => $addressGetter->getPostCode(),
                "countryCode" => $addressGetter->getCountryId(),
                "shippingType" => "ST" // ST - for standard, SD - for same day
            ],
            "billingAddress" => [
                "line1" => $addressGetter->getStreet(1)[0],
                "line2" => $addressGetter->getStreet(2)[0] ?? '',
                "city" => $addressGetter->getCity(),
                "state" => $addressGetter->getRegionCode(),
                "zipCode" => $addressGetter->getPostCode(),
                "countryCode" => $addressGetter->getCountryId(),
            ]
        ];

        $payMayaArray = [
            "totalAmount" => [
                "value" => $order->getTotalDue(),
                "currency" => $order->getOrderCurrencyCode(),
                "details" => [
                    "discount" => 0,
                    "serviceCharge" => 0,
                    "shippingFee" => $order->getShippingAmount(),
                    "tax" => $order->getTaxAmount(),
                    "subtotal" => $order->getBaseSubtotal()
                ]
            ],
            "buyer" => $buyerData,
            "items"=> $orderItems,
            "redirectUrl" => [
                "success" => "{$baseUrl}paymaya/checkout/catcher?type=success",
                "failure" => "{$baseUrl}paymaya/checkout/catcher?type=fail",
                "cancel" => "{$baseUrl}paymaya/checkout/catcher?type=cancel"
            ],
            "requestReferenceNumber" => $order->getIncrementId(),
        ];

        return $payMayaArray;
    }
}
