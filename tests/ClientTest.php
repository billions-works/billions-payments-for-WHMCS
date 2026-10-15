<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/gateways/billions/Client.php';

use Billions\WHMCS\Client;
use Billions\WHMCS\GatewayException;

function expectFailure(callable $callback): void
{
    try {
        $callback();
    } catch (GatewayException $expected) {
        return;
    }
    throw new RuntimeException('Expected gateway validation to fail');
}

$id = '11111111-1111-4111-8111-111111111111';
$remote = [
    'id' => $id,
    'order_no' => 'whmcs-prod_1-42',
    'settlement_currency' => 'USD',
    'amount' => '10.00',
    'invoice_url' => 'https://pay.billions.works/invoices/' . $id,
];

if (Client::orderNumber('prod_1', 42) !== $remote['order_no']
    || Client::callbackUrl('https://billing.example.com/whmcs/', 42)
        !== 'https://billing.example.com/whmcs/modules/gateways/callback/billions.php?invoiceid=42'
    || Client::decimal('0010.00') !== '10'
    || Client::checkoutUrl($remote) !== $remote['invoice_url']
    || Client::webhookInvoiceId(['data' => ['invoice_id' => $id]]) !== $id) {
    throw new RuntimeException('Gateway helpers returned unexpected values');
}

Client::assertInvoiceMatches($remote, 'prod_1', 42, 'USD', '10');
expectFailure(static fn () => Client::orderNumber('bad id', 42));
expectFailure(static fn () => Client::callbackUrl('http://billing.example.com', 42));
expectFailure(static fn () => Client::assertInvoiceMatches($remote, 'prod_1', 43, 'USD', '10'));
expectFailure(static fn () => Client::assertInvoiceMatches($remote, 'prod_1', 42, 'EUR', '10'));
expectFailure(static fn () => Client::assertInvoiceMatches($remote, 'prod_1', 42, 'USD', '11'));
expectFailure(static fn () => Client::checkoutUrl(['invoice_url' => 'http://bad.example']));

echo "Client tests passed\n";
