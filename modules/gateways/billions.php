<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__ . '/billions/Client.php';

use Billions\WHMCS\Client;
use Billions\WHMCS\GatewayException;

/** @return array<string, string> */
function billions_MetaData(): array
{
    return [
        'DisplayName' => 'Billions.works',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/** @return array<string, array<string, string>> */
function billions_config(): array
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Billions.works'],
        'apiKey' => [
            'FriendlyName' => 'Public API key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Merchant API key from Billions.works',
        ],
        'instanceId' => [
            'FriendlyName' => 'Unique WHMCS instance ID',
            'Type' => 'text',
            'Size' => '30',
            'Description' => '3–30 letters, digits, underscores or hyphens. Never change after the first payment.',
        ],
    ];
}

/** @param array<string, mixed> $params */
function billions_link(array $params): string
{
    try {
        $whmcsInvoiceId = filter_var($params['invoiceid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($whmcsInvoiceId === false) {
            throw new GatewayException('Invalid WHMCS invoice ID');
        }
        $instanceId = trim((string) ($params['instanceId'] ?? ''));
        $amount = Client::decimal((string) ($params['amount'] ?? ''));
        $currency = strtoupper(trim((string) ($params['currency'] ?? '')));
        if ($amount === '0' || !preg_match('/^[A-Z0-9_]{2,20}$/', $currency)) {
            throw new GatewayException('Invalid invoice amount or currency');
        }
        $orderNumber = Client::orderNumber($instanceId, $whmcsInvoiceId);
        $description = trim((string) ($params['description'] ?? ''));
        if ($description !== '') {
            $description = function_exists('mb_substr')
                ? mb_substr($description, 0, 200, 'UTF-8')
                : substr($description, 0, 200);
        }
        $payload = [
            'currency' => $currency,
            'amount' => $amount,
            'type' => 'b2c',
            'order_no' => $orderNumber,
            'idempotencyKey' => $orderNumber,
            'callbackUrl' => Client::callbackUrl((string) ($params['systemurl'] ?? ''), $whmcsInvoiceId),
        ];
        if ($description !== '') {
            $payload['description'] = $description;
        }
        $api = new Client(trim((string) ($params['apiKey'] ?? '')));
        $remote = $api->createInvoice($payload);
        Client::assertInvoiceMatches($remote, $instanceId, $whmcsInvoiceId, $currency, $amount);
        if (!in_array((string) ($remote['status'] ?? ''), ['pending', 'processing'], true)) {
            throw new GatewayException('Billions invoice is not payable');
        }
        $url = Client::checkoutUrl($remote);
        $label = (string) ($params['langpaynow'] ?? 'Pay now');
        return '<a class="btn btn-primary" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" rel="noopener noreferrer">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    } catch (\Throwable $error) {
        error_log('Billions WHMCS payment link: ' . $error->getMessage());
        return '<div class="alert alert-danger">Unable to open Billions checkout. Please contact support.</div>';
    }
}
