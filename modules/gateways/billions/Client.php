<?php

declare(strict_types=1);

namespace Billions\WHMCS;

final class GatewayException extends \RuntimeException
{
}

final class Client
{
    private const BASE_URL = 'https://api.billions.works/public/v1';

    private string $apiKey;

    public function __construct(string $apiKey)
    {
        if ($apiKey === '' || preg_match('/[\x00-\x20\x7f]/', $apiKey)) {
            throw new GatewayException('Invalid Billions API key');
        }
        $this->apiKey = $apiKey;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createInvoice(array $payload): array
    {
        return $this->request('POST', '/invoices/create', $payload);
    }

    /** @return array<string, mixed> */
    public function getInvoice(string $id): array
    {
        if (!self::isInvoiceId($id)) {
            throw new GatewayException('Invalid Billions invoice ID');
        }
        return $this->request('GET', '/invoices/' . rawurlencode($id));
    }

    /** @param array<string, mixed>|null $payload @return array<string, mixed> */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $handle = curl_init(self::BASE_URL . $path);
        if ($handle === false) {
            throw new GatewayException('Could not initialize HTTPS request');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'User-Agent: Billions-WHMCS/0.1.0',
            ],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new GatewayException('Billions API request failed (HTTP ' . $status . ')');
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new GatewayException('Invalid Billions API response');
        }
        return $decoded;
    }

    public static function isInvoiceId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    public static function orderNumber(string $instanceId, int $invoiceId): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{3,30}$/', $instanceId) || $invoiceId < 1) {
            throw new GatewayException('Invalid WHMCS instance or invoice ID');
        }
        return 'whmcs-' . $instanceId . '-' . $invoiceId;
    }

    public static function callbackUrl(string $systemUrl, int $invoiceId): string
    {
        $parts = parse_url($systemUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new GatewayException('WHMCS System URL must use HTTPS');
        }
        return rtrim($systemUrl, '/') . '/modules/gateways/callback/billions.php?invoiceid=' . $invoiceId;
    }

    public static function decimal(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new GatewayException('Invalid invoice amount');
        }
        $parts = explode('.', $value, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($parts[1] ?? '', '0');
        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    /** @param array<string, mixed> $remote */
    public static function assertInvoiceMatches(
        array $remote,
        string $instanceId,
        int $whmcsInvoiceId,
        string $currency,
        string $amount
    ): void {
        self::assertInvoiceIdentity($remote, $instanceId, $whmcsInvoiceId, $currency);
        if (self::decimal((string) ($remote['amount'] ?? '')) !== self::decimal($amount)) {
            throw new GatewayException('Billions amount does not match WHMCS balance');
        }
    }

    /** @param array<string, mixed> $remote */
    public static function assertInvoiceIdentity(
        array $remote,
        string $instanceId,
        int $whmcsInvoiceId,
        string $currency
    ): void {
        if (!self::isInvoiceId((string) ($remote['id'] ?? ''))
            || ($remote['order_no'] ?? null) !== self::orderNumber($instanceId, $whmcsInvoiceId)
            || strtoupper((string) ($remote['settlement_currency'] ?? '')) !== strtoupper($currency)) {
            throw new GatewayException('Billions invoice does not match WHMCS invoice');
        }
    }

    /** @param array<string, mixed> $remote */
    public static function checkoutUrl(array $remote): string
    {
        $url = (string) ($remote['invoice_url'] ?? '');
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\r\n]/', $url)) {
            throw new GatewayException('Invalid Billions checkout URL');
        }
        return $url;
    }

    /** @param array<string, mixed> $event */
    public static function webhookInvoiceId(array $event): string
    {
        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : $event;
        return trim((string) ($data['invoice_id'] ?? $data['id'] ?? ''));
    }
}
