<?php

namespace App\Payments;

use App\Enums\PaymentKind;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * pawaPay mobile money (Orange Money, Afrimoney) using the v1 REST API:
 *   POST /deposits, GET /deposits/{id}   buyer pays WiBuy
 *   POST /refunds,  GET /refunds/{id}    WiBuy pays a buyer back
 *   POST /payouts,  GET /payouts/{id}    WiBuy pays a seller
 * Sandbox by default (PAWAPAY_BASE_URL). The response parser also accepts the
 * newer {"status":"FOUND","data":{...}} envelope.
 */
class PawaPayGateway implements PaymentGateway
{
    public function __construct(
        private string $baseUrl,
        private ?string $token,
        private string $currency,
        private int $timeout = 15,
        private string $statementDescription = 'WiBuy order',
    ) {}

    public function initiateDeposit(Payment $deposit): GatewayResult
    {
        $response = $this->http()->post('/deposits', [
            'depositId' => $deposit->provider_reference,
            'amount' => $this->amount($deposit),
            'currency' => $this->currency,
            'correspondent' => $deposit->operator->gatewayCode(),
            'payer' => [
                'type' => 'MSISDN',
                'address' => ['value' => $deposit->phone],
            ],
            'customerTimestamp' => $this->timestamp(),
            'statementDescription' => $this->statementDescription,
        ])->throw();

        return $this->mapInitiation($response->json() ?? []);
    }

    public function initiateRefund(Payment $refund, Payment $deposit): GatewayResult
    {
        $response = $this->http()->post('/refunds', [
            'refundId' => $refund->provider_reference,
            'depositId' => $deposit->provider_reference,
            'amount' => $this->amount($refund),
        ])->throw();

        return $this->mapInitiation($response->json() ?? []);
    }

    public function initiatePayout(Payment $payout): GatewayResult
    {
        $response = $this->http()->post('/payouts', [
            'payoutId' => $payout->provider_reference,
            'amount' => $this->amount($payout),
            'currency' => $this->currency,
            'correspondent' => $payout->operator->gatewayCode(),
            'recipient' => [
                'type' => 'MSISDN',
                'address' => ['value' => $payout->phone],
            ],
            'customerTimestamp' => $this->timestamp(),
            'statementDescription' => 'WiBuy payout',
        ])->throw();

        return $this->mapInitiation($response->json() ?? []);
    }

    public function fetchStatus(Payment $payment): GatewayResult
    {
        $path = match ($payment->kind) {
            PaymentKind::Deposit, PaymentKind::Membership, PaymentKind::Charge => '/deposits/',
            PaymentKind::Refund => '/refunds/',
            PaymentKind::Payout => '/payouts/',
        };

        $response = $this->http()->get($path.$payment->provider_reference);

        if ($response->status() === 404) {
            return GatewayResult::notFound();
        }

        $response->throw();

        $item = $this->extractItem($response->json());

        if ($item === null) {
            return GatewayResult::notFound((array) $response->json());
        }

        return $this->mapStatus($item);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken((string) $this->token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    private function amount(Payment $payment): string
    {
        return bcadd((string) $payment->amount, '0', 2);
    }

    private function timestamp(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** Response to creating a deposit/refund/payout: ACCEPTED, REJECTED or DUPLICATE_IGNORED. */
    private function mapInitiation(array $body): GatewayResult
    {
        $status = strtoupper((string) ($body['status'] ?? ''));

        if ($status === 'REJECTED') {
            return GatewayResult::failed($this->reason($body) ?? 'Rejected by pawaPay', $body);
        }

        // ACCEPTED and DUPLICATE_IGNORED both mean "wait for the final status".
        return GatewayResult::pending($body);
    }

    private function mapStatus(array $item): GatewayResult
    {
        return match (strtoupper((string) ($item['status'] ?? ''))) {
            'COMPLETED' => GatewayResult::completed($item),
            'FAILED', 'REJECTED' => GatewayResult::failed($this->reason($item) ?? 'Payment failed', $item),
            default => GatewayResult::pending($item),
        };
    }

    private function extractItem(mixed $body): ?array
    {
        if (! is_array($body)) {
            return null;
        }

        if (array_is_list($body)) {
            return is_array($body[0] ?? null) ? $body[0] : null;
        }

        if (isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        if (strtoupper((string) ($body['status'] ?? '')) === 'NOT_FOUND') {
            return null;
        }

        return $body;
    }

    private function reason(array $item): ?string
    {
        return $item['failureReason']['failureMessage']
            ?? $item['failureReason']['failureCode']
            ?? $item['rejectionReason']['rejectionMessage']
            ?? $item['rejectionReason']['rejectionCode']
            ?? null;
    }
}
