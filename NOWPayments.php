<?php

namespace Paymenter\Extensions\Gateways\Nowpayments;

use App\Classes\Extension\Gateway;
use App\Models\Invoice;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class Nowpayments extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php'; // Register webhook route
    }

    public function getMetadata()
    {
        return [
            'display_name' => 'NowPayments',
            'version' => '1.0.0',
            'author' => 'Enjuu',
            'website' => 'https://nowpayments.io',
        ];
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'text',
                'required' => true,
                'description' => 'Your NowPayments API Key',
            ],
            [
                'name' => 'ipn_secret',
                'label' => 'IPN Secret Key',
                'type' => 'text',
                'required' => true,
                'description' => 'Your IPN Secret Key for webhook verification',
            ],
            [
                'name' => 'currency',
                'label' => 'Base Currency',
                'type' => 'text',
                'required' => true,
                'default' => 'USD',
                'description' => 'Fiat currency for price calculation (USD, EUR, GBP, etc.)',
            ],
            [
                'name' => 'sandbox_mode',
                'label' => 'Sandbox Mode',
                'type' => 'checkbox',
                'required' => false,
                'default' => false,
                'description' => 'Enable sandbox mode for testing',
            ],
        ];
    }

    public function pay(Invoice $invoice, $total)
    {
        $cacheKey = "nowpayments_payment_url_{$invoice->id}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $apiKey = trim($this->config('api_key'));
        $sandboxMode = $this->config('sandbox_mode', false);
        $currency = $invoice->currency_code ?? trim($this->config('currency'));
        
        // Use sandbox or production URL based on configuration
        $baseUrl = $sandboxMode 
            ? 'https://api-sandbox.nowpayments.io/v1' 
            : 'https://api.nowpayments.io/v1';

        // First, check minimum payment amount
        $minAmountResponse = Http::withHeaders([
            'x-api-key' => $apiKey,
        ])->get($baseUrl . '/min-amount', [
            'currency_from' => strtolower($currency),
            'currency_to' => 'btc', // Check against BTC as reference
        ]);

        if (!$minAmountResponse->successful()) {
            Log::error('NowPayments Min Amount Check Failed', [
                'response' => $minAmountResponse->body()
            ]);
            return false;
        }

        // Create payment
        $paymentData = [
            'price_amount' => number_format($total, 2, '.', ''),
            'price_currency' => strtolower($currency),
            'order_id' => (string) $invoice->id,
            'order_description' => 'Invoice #' . $invoice->id,
            'ipn_callback_url' => url('/extensions/gateways/nowpayments/webhook'),
            'success_url' => route('invoices.show', $invoice),
            'cancel_url' => route('invoices.show', $invoice),
            'is_fixed_rate' => false,
            'is_fee_paid_by_user' => false,
        ];

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ])->post($baseUrl . '/invoice', $paymentData);

        if ($response->successful()) {
            $result = $response->json();
            $paymentUrl = $result['invoice_url'] ?? null;
            
            if ($paymentUrl) {
                // Cache the payment URL for 1 hour
                Cache::put($cacheKey, $paymentUrl, 3600);
                
                // Also cache the payment ID for webhook verification
                Cache::put("nowpayments_payment_{$invoice->id}", $result['id'], 86400);
                
                return $paymentUrl;
            }
        }

        Log::error('NowPayments Payment Creation Error', [
            'response' => $response->body(),
            'status' => $response->status(),
            'data' => $paymentData,
        ]);

        return false;
    }

    public function webhook(Request $request)
    {
        // Get IPN secret for verification
        $ipnSecret = trim($this->config('ipn_secret'));
        
        // Get raw request body
        $rawContent = $request->getContent();
        $data = json_decode($rawContent, true);
        
        // Log incoming webhook
        Log::debug('NowPayments Webhook Received', [
            'headers' => $request->headers->all(),
            'data' => $data,
        ]);
        
        // Verify JSON is valid
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON in NowPayments webhook', [
                'error' => json_last_error_msg()
            ]);
            return response()->json(['error' => 'Invalid JSON'], 400);
        }

        // Verify signature
        $receivedSignature = $request->header('x-nowpayments-sig');
        if (!$receivedSignature) {
            Log::error('Missing signature in NowPayments webhook');
            return response()->json(['error' => 'Missing signature'], 400);
        }

        // NowPayments uses HMAC-SHA512 for signature
        $calculatedSignature = hash_hmac('sha512', $rawContent, $ipnSecret);
        
        if (!hash_equals($calculatedSignature, $receivedSignature)) {
            Log::error('Invalid NowPayments webhook signature', [
                'received' => $receivedSignature,
                'calculated' => $calculatedSignature,
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Extract payment data
        $paymentId = $data['payment_id'] ?? null;
        $invoiceId = $data['order_id'] ?? null;
        $paymentStatus = $data['payment_status'] ?? null;
        $actuallyPaid = $data['actually_paid'] ?? 0;
        $priceAmount = $data['price_amount'] ?? 0;
        $payCurrency = $data['pay_currency'] ?? null;
        
        if (!$invoiceId || !$paymentStatus) {
            Log::error('Missing required fields in NowPayments webhook', $data);
            return response()->json(['error' => 'Missing required fields'], 400);
        }

        // Process payment based on status
        switch ($paymentStatus) {
            case 'finished':
            case 'confirmed':
                // Payment is complete
                $transactionId = $paymentId . '_' . ($data['payment_extra_id'] ?? '');
                ExtensionHelper::addPayment(
                    $invoiceId, 
                    'NowPayments', 
                    $priceAmount,
                    transactionId: $transactionId
                );
                
                Log::info('NowPayments Payment Completed', [
                    'invoice_id' => $invoiceId,
                    'payment_id' => $paymentId,
                    'amount' => $priceAmount,
                    'currency' => $payCurrency,
                ]);
                break;
                
            case 'partially_paid':
                // Log partial payment but don't mark as complete
                Log::warning('NowPayments Partial Payment Received', [
                    'invoice_id' => $invoiceId,
                    'payment_id' => $paymentId,
                    'expected' => $priceAmount,
                    'received' => $actuallyPaid,
                ]);
                break;
                
            case 'expired':
            case 'failed':
            case 'refunded':
                // Payment failed or was cancelled
                Log::warning('NowPayments Payment Failed', [
                    'invoice_id' => $invoiceId,
                    'payment_id' => $paymentId,
                    'status' => $paymentStatus,
                ]);
                break;
                
            case 'waiting':
            case 'confirming':
            case 'sending':
                // Payment is still processing
                Log::info('NowPayments Payment Processing', [
                    'invoice_id' => $invoiceId,
                    'payment_id' => $paymentId,
                    'status' => $paymentStatus,
                ]);
                break;
                
            default:
                Log::warning('Unknown NowPayments payment status', [
                    'status' => $paymentStatus,
                    'data' => $data,
                ]);
        }

        return response()->json(['success' => true]);
    }
}