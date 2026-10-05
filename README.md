# Maya Business Plugin

This is the repository of the official Maya Magento payment gateway extension

## Give your customers a better online checkout experience

With Maya Checkout, your website or app can directly accept credit and debit cards, e-wallet, and other emerging payment solutions.

* Mastercard
* Visa
* JCB
* WeChat Pay
* Pay With Maya

### Features

* Payments via Maya Checkout
* Full 3DS Support and PCI-DSS Compliant
* Checkout page customizations

## Don't have an account yet? [Click here to get started](https://developers.maya.ph/docs/magento-2)

### Version Compatibility
This version (1.3.0) is currently compatible with the following Magento version:
* 2.4


### Changelog

#### 1.3.0

**Security**
* Payment webhooks are now verified with Maya before an order changes state. The module asks Maya for the payment and updates the order only if Maya confirms the payment, the order reference, the status, the amount and the currency. The content of the webhook request is no longer trusted. Upgrading from any earlier version is strongly recommended.
* The Maya webhook endpoints accept POST requests only and return proper HTTP status codes, so Maya retries a webhook when the module cannot process it yet.
* Buyer details and raw webhook bodies are no longer written to the Maya log.

**Behaviour changes**
* A payment failure or expiry notice no longer cancels an order that was placed before this version. Those orders stay pending and can be canceled by the merchant.
* If Maya confirms a payment for an order that was already canceled in Magento and its items released, the order is not reopened. The order receives a comment and a critical log entry so the merchant can fulfil or refund it.
* The payment page creates a Maya checkout only for an order that was placed with Maya and is still awaiting payment.
* The Maya checkout IDs of an order are stored on its payment information.

**Upgrade notes**
* Run `bin/magento setup:di:compile` on production stores, then flush the cache.
* The secret key of the active mode must be correct. A wrong key makes webhook handling return an error and Maya keeps retrying.
* Limit traffic to the webhook endpoints at the web server or firewall, ideally to the webhook source addresses that Maya publishes for each environment.

