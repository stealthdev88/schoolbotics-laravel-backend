<?php

namespace App\Services\Payment;

// use JetBrains\PhpStorm\Pure;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Illuminate\Support\Facades\Log;

class StripePayment implements PaymentInterface {
    private StripeClient $stripe;
    private string $currencyCode;

    // #[Pure] 
    public function __construct($secretKey, $currencyCode) {
        // Call Stripe Class and Create Payment Intent
        Log::channel('custom')->error('StripePayment secretKey:' . $secretKey);
        $this->stripe = new StripeClient($secretKey);
        Log::channel('custom')->error('StripePayment secretKey:' . json_encode($this->stripe));
        $this->currencyCode = $currencyCode;
    }

    /**
     * @param $amount
     * @param $customMetaData
     * @return PaymentIntent
     * @throws ApiErrorException
     */
    public function createPaymentIntent($amount, $customMetaData) {
        try {
            Log::channel('custom')->error('stripe payment createpaymentIntent customMetaData:' . json_encode($customMetaData));
            $amount = $this->minimumAmountValidation($this->currencyCode, $amount);
            $amount *= 100;
            return $this->stripe->paymentIntents->create(
                [
                    'amount'   => $amount,
                    'currency' => $this->currencyCode,
                    'metadata' => $customMetaData,
                    //                    'description' => 'Fees Payment',
                    //                    'shipping' => [
                    //                        'name' => 'Jenny Rosen',
                    //                        'address' => [
                    //                            'line1' => '510 Townsend St',
                    //                            'postal_code' => '98140',
                    //                            'city' => 'San Francisco',
                    //                            'state' => 'CA',
                    //                            'country' => 'US',
                    //                        ],
                    //                    ],
                ]
            );
        } catch (ApiErrorException $e) {
            throw $e;
        }
    }

    /**
     * @param $paymentId
     * @return PaymentIntent
     * @throws ApiErrorException
     */
    public function retrievePaymentIntent($paymentId) {
        Log::channel('custom')->error('stripe payment createpaymentIntent paymentId:' . $paymentId);
        try {
            return $this->stripe->paymentIntents->retrieve($paymentId);
        } catch (ApiErrorException $e) {
            throw $e;
        }
    }


    /**
     * @param $currency
     * @param $amount
     * @return float|int
     */
    public function minimumAmountValidation($currency, $amount) {
        $minimumAmount = match ($currency) {
            'USD', 'EUR', 'INR', 'NZD', 'SGD', 'BRL', 'CAD', 'AUD', 'CHF' => 0.50,
            'AED', 'PLN', 'RON' => 2.00,
            'BGN' => 1.00,
            'CZK' => 15.00,
            'DKK' => 2.50,
            'GBP' => 0.30,
            'HKD' => 4.00,
            'HUF' => 175.00,
            'JPY' => 50,
            'MXN', 'THB' => 10,
            'MYR' => 2,
            'NOK', 'SEK' => 3.00,
            default => null,
        };
        if (!empty($minimumAmount)) {
            if ($amount > $minimumAmount) {
                return $amount;
            }

            return $minimumAmount;
        }

        return $amount;
    }
}
