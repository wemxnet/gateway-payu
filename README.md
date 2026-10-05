# PayU

Accept payments in Indian rupees on WemX through PayU India's hosted checkout.

## Features

- Send the customer to the PayU checkout with a signed (SHA-512) payment form
- Verify the reverse hash PayU returns, then confirm the transaction with PayU's `verify_payment` API before marking the payment paid
- Reject returns whose transaction id, status, or amount do not match the WemX payment

## Install

Install from the WemX marketplace, or download `PayU.zip` from a GitHub release and place the `PayU` folder at `extensions/Gateways/PayU`. Then enable **PayU**.

Publishing a release builds `PayU.zip`. Unzipping it creates a folder named `PayU`. GitHub's own "Source code" archive unpacks to `gateway-payu-<tag>`. Use `PayU.zip`.

## Connection

Add a gateway configuration with your PayU merchant key and salt from the PayU dashboard. Set **Test mode** to Enabled while you use test credentials, which sends payments to `test.payu.in`.

PayU only accepts INR, so WemX converts other currencies to INR at checkout. Make sure INR is set up under currencies.

## Credits

Based on the MIT-licensed PayU extension from [Paymenter](https://github.com/Paymenter/Extensions).
