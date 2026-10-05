<?php

namespace Extensions\Gateways\PayU;

use App\Extensions\Foundation\GatewayExtension;
use App\Models\GatewayConfig;
use App\Models\Payment;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Gateway extends GatewayExtension
{
    protected string $id = 'gateway-payu';

    protected string $name = 'PayU';

    protected string $description = 'Accept INR payments with PayU India hosted checkout: cards, UPI, net banking and wallets.';

    protected string $type = 'Gateway';

    protected string $gatewayType = 'payment';

    protected array $currencies = ['INR'];

    protected string $version = '1.0.0';

    protected array $wemxVersions = ['*'];

    protected array $authors = [
        [
            'name' => 'WemX',
            'email' => 'team@wemx.net',
        ],
    ];

    public string $gatewayDescription = 'Pay with cards, UPI, net banking or wallets through PayU.';

    public static function setConfig(): array
    {
        return [
            'merchant_key' => [
                'label' => 'Merchant key',
                'description' => 'From the PayU dashboard under Developers → API keys.',
                'type' => 'text',
                'rules' => ['required', 'string'],
            ],
            'merchant_salt' => [
                'label' => 'Merchant salt (v1)',
                'description' => 'The 32-bit salt shown next to the merchant key.',
                'type' => 'password',
                'rules' => ['required', 'string'],
            ],
            'test_mode' => [
                'label' => 'Test mode',
                'description' => 'Use PayU\'s test environment. Use test credentials while this is on.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled',
                    '1' => 'Enabled',
                ],
                'default_value' => '0',
                'rules' => ['required', 'in:0,1'],
            ],
        ];
    }

    /**
     * Redirect the customer to PayU with an auto-submitting form.
     */
    public function pay(Payment $payment, GatewayConfig $gatewayConfig): Response
    {
        $user = $payment->user;
        $transactionId = 'wx'.$payment->id.'x'.Str::lower(Str::random(8));

        $fields = [
            'key' => (string) $gatewayConfig->config('merchant_key'),
            'txnid' => $transactionId,
            'amount' => $this->formatAmount($payment->total()),
            'productinfo' => Str::limit(Str::ascii((string) ($payment->description ?: 'Payment #'.$payment->id)), 95, ''),
            'firstname' => Str::limit(Str::ascii((string) ($user?->first_name ?: $user?->username ?: 'Customer')), 60, ''),
            'email' => (string) $user?->email,
            'phone' => (string) ($user?->phone ?? ''),
            'surl' => $payment->callbackUrl(),
            'furl' => $payment->callbackUrl(),
        ];

        $fields['hash'] = $this->requestHash($fields, (string) $gatewayConfig->config('merchant_salt'));

        $payment->update(['transaction_id' => $transactionId]);

        return response($this->redirectForm($this->baseUrl($gatewayConfig).'/_payment', $fields));
    }

    /**
     * PayU posts the customer back here after success or failure.
     */
    public function callback(Request $request, GatewayConfig $gatewayConfig): RedirectResponse
    {
        $payment = Payment::query()->where('token', $request->query('payment_token'))->first();

        if (! $payment) {
            throw new Exception('Payment not found.');
        }

        if ($payment->isPaid()) {
            return redirect($payment->successUrl());
        }

        $transactionId = (string) $request->input('txnid');

        if ($transactionId === '' || $transactionId !== (string) $payment->transaction_id) {
            throw new Exception('PayU transaction does not match this payment.');
        }

        if (! hash_equals($this->responseHash($request->all(), $gatewayConfig), (string) $request->input('hash'))) {
            throw new Exception('Invalid PayU response signature.');
        }

        $transaction = $this->verifyTransaction($transactionId, $gatewayConfig);

        $status = strtolower((string) ($transaction['status'] ?? ''));
        $amount = (float) ($transaction['amt'] ?? $transaction['transaction_amount'] ?? 0);

        if ($status === 'success' && abs($amount - $payment->total()) < 0.01) {
            $payment->completed((string) ($transaction['mihpayid'] ?? $transactionId), $transaction);
            $payment->logPaymentWebhook('Payment completed via PayU callback');

            return redirect($payment->successUrl());
        }

        $payment->logPaymentWebhook('PayU payment was not completed: '.$status);

        return redirect($payment->cancelUrl());
    }

    /**
     * @param  array<string, string>  $fields
     */
    public function requestHash(array $fields, string $salt): string
    {
        $parts = [
            $fields['key'], $fields['txnid'], $fields['amount'], $fields['productinfo'], $fields['firstname'], $fields['email'],
            $fields['udf1'] ?? '', $fields['udf2'] ?? '', $fields['udf3'] ?? '', $fields['udf4'] ?? '', $fields['udf5'] ?? '',
            '', '', '', '', '', $salt,
        ];

        return strtolower(hash('sha512', implode('|', $parts)));
    }

    /**
     * Reverse hash PayU sends back: salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key
     *
     * @param  array<string, mixed>  $response
     */
    public function responseHash(array $response, GatewayConfig $gatewayConfig): string
    {
        $parts = [
            (string) $gatewayConfig->config('merchant_salt'), $response['status'] ?? '',
            '', '', '', '', '',
            $response['udf5'] ?? '', $response['udf4'] ?? '', $response['udf3'] ?? '', $response['udf2'] ?? '', $response['udf1'] ?? '',
            $response['email'] ?? '', $response['firstname'] ?? '', $response['productinfo'] ?? '', $response['amount'] ?? '',
            $response['txnid'] ?? '', (string) $gatewayConfig->config('merchant_key'),
        ];

        $hashString = implode('|', array_map('strval', $parts));

        if (! empty($response['additionalCharges'])) {
            $hashString = $response['additionalCharges'].'|'.$hashString;
        }

        return strtolower(hash('sha512', $hashString));
    }

    /**
     * @return array<string, mixed>
     */
    protected function verifyTransaction(string $transactionId, GatewayConfig $gatewayConfig): array
    {
        $key = (string) $gatewayConfig->config('merchant_key');
        $command = 'verify_payment';

        $response = Http::asForm()->acceptJson()->timeout(30)->post($this->verifyUrl($gatewayConfig), [
            'key' => $key,
            'command' => $command,
            'var1' => $transactionId,
            'hash' => strtolower(hash('sha512', implode('|', [$key, $command, $transactionId, (string) $gatewayConfig->config('merchant_salt')]))),
        ]);

        if ($response->failed()) {
            throw new Exception('Could not verify the payment with PayU.');
        }

        $transaction = $response->json("transaction_details.{$transactionId}");

        if (! is_array($transaction)) {
            throw new Exception('PayU did not return the transaction.');
        }

        return $transaction;
    }

    protected function baseUrl(GatewayConfig $gatewayConfig): string
    {
        return (string) $gatewayConfig->config('test_mode', '0') === '1' ? 'https://test.payu.in' : 'https://secure.payu.in';
    }

    protected function verifyUrl(GatewayConfig $gatewayConfig): string
    {
        return (string) $gatewayConfig->config('test_mode', '0') === '1'
            ? 'https://test.payu.in/merchant/postservice?form=2'
            : 'https://info.payu.in/merchant/postservice?form=2';
    }

    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * @param  array<string, string>  $fields
     */
    protected function redirectForm(string $action, array $fields): string
    {
        $inputs = collect($fields)
            ->map(fn ($value, $name) => '<input type="hidden" name="'.e($name).'" value="'.e($value).'">')
            ->implode('');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Redirecting to PayU</title></head>'
            .'<body onload="document.forms[0].submit()"><form method="post" action="'.e($action).'">'.$inputs
            .'<noscript><button type="submit">Continue to PayU</button></noscript></form></body></html>';
    }
}
