<?php

namespace App\Services\Payment;

use Exception;
use Yabacon\Paystack;
use Yabacon\Paystack\Exception\ApiException;
use Illuminate\Support\Facades\Log;

class PaystackPayment implements PaymentInterface {
    private Paystack $paystack;
    private string $currencyCode;

    public function __construct($secretKey, $currencyCode) {
        
        Log::channel('custom')->error('PaystackPayment secretKey333:' . $secretKey);           
        $this->paystack = new Paystack($secretKey);
        Log::channel('custom')->error('PaystackPayment currencyCode333:' . $currencyCode);
        $this->currencyCode = $currencyCode;
    }

    /**
     * @param $amount
     * @param $customMetaData
     * @return array
     * @throws Exception
     */
    public function createPaymentIntent($amount, $customMetaData) {
        try {
            Log::channel('custom')->error('PaystackPayment customMetaData:' . json_encode($customMetaData));           
            $amount = $this->minimumAmountValidation($this->currencyCode, $amount);
            Log::channel('custom')->error('PaystackPayment payment_transaction_id:' . $customMetaData['payment_transaction_id']);
            
            $amount *= 100; // Convert to smallest currency unit
            $tranx = $this->paystack->transaction->initialize([
                'amount' => $amount,
                'email' => $customMetaData['email'],
                'currency' => $this->currencyCode,
                'reference' => $customMetaData['payment_transaction_id'],
            ]);
            
            Log::channel('custom')->error('PaystackPayment tranx:' . json_encode($tranx));
            return $tranx->data;
        } catch (ApiException $e) {
            Log::channel('custom')->error('ApiException error:' . json_encode($e->getResponseObject()));
            Log::channel('custom')->error('ApiException message:' . json_encode($e->getMessage()));
            throw $e;
        }
    }

    /**
     * @param $paymentId
     * @return array
     * @throws Exception
     */
    public function retrievePaymentIntent($paymentId) {
        try {
            Log::channel('custom')->error('retrievePaymentIntent paymentId:' . $paymentId);
            $tranx = $this->paystack->transaction->verify([
                'reference' => $paymentId
            ]);
            Log::channel('custom')->error('retrievePaymentIntent tranx:' . json_encode($tranx));
            return $tranx->data;
        } catch (Exception $e) {
            Log::channel('custom')->error('ApiException error:' . json_encode($e->getResponseObject()));
            Log::channel('custom')->error('ApiException message:' . json_encode($e->getMessage()));
            throw $e;
        }
    }

    /**
     * @param $currency
     * @param $amount
     * @return float|int
     */
    public function minimumAmountValidation($currency, $amount) {
        // Similar logic as in StripePayment for minimum amount validation
        // ... existing code ...
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
            'GHS' => 1,
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