<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../billions/Client.php';

use Billions\WHMCS\Client;
use Billions\WHMCS\GatewayException;
use WHMCS\Billing\Invoice;

function billions_callback_reply(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
        throw new GatewayException('Invalid callback request');
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) {
        throw new GatewayException('Invalid callback body');
    }
    $event = json_decode($raw, true);
    if (!is_array($event)) {
        throw new GatewayException('Invalid callback JSON');
    }
    $eventInvoiceId = Client::webhookInvoiceId($event);
    if (!Client::isInvoiceId($eventInvoiceId)) {
        throw new GatewayException('Missing Billions invoice ID');
    }
    $whmcsInvoiceId = filter_var($_GET['invoiceid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($whmcsInvoiceId === false) {
        throw new GatewayException('Invalid WHMCS invoice ID');
    }
    $gatewayParams = getGatewayVariables('billions');
    if (empty($gatewayParams['type'])) {
        throw new GatewayException('Billions gateway is not active');
    }
    $whmcsInvoiceId = (int) checkCbInvoiceID($whmcsInvoiceId, 'billions');
    $api = new Client(trim((string) ($gatewayParams['apiKey'] ?? '')));
    $remote = $api->getInvoice($eventInvoiceId);
    $invoice = Invoice::find($whmcsInvoiceId);
    if ($invoice === null || (string) $invoice->paymentmethod !== 'billions') {
        throw new GatewayException('WHMCS invoice or gateway mismatch');
    }
    $currency = (string) $invoice->currencyCode;
    Client::assertInvoiceIdentity(
        $remote,
        trim((string) ($gatewayParams['instanceId'] ?? '')),
        $whmcsInvoiceId,
        $currency
    );
    $status = (string) ($remote['status'] ?? '');
    if ($status !== 'completed') {
        logTransaction('billions', ['invoice_id' => $eventInvoiceId, 'status' => $status], 'Not completed');
        billions_callback_reply(200, 'OK');
        exit;
    }
    checkCbTransID($eventInvoiceId);
    $balance = Client::decimal((string) $invoice->balance);
    if ($balance === '0' || !in_array((string) $invoice->status, ['Unpaid', 'Payment Pending'], true)) {
        throw new GatewayException('WHMCS invoice is no longer payable');
    }
    Client::assertInvoiceMatches(
        $remote,
        trim((string) ($gatewayParams['instanceId'] ?? '')),
        $whmcsInvoiceId,
        $currency,
        $balance
    );
    logTransaction('billions', ['invoice_id' => $eventInvoiceId, 'status' => $status], 'Verified');
    addInvoicePayment($whmcsInvoiceId, $eventInvoiceId, (float) $balance, 0.0, 'billions');
    billions_callback_reply(200, 'OK');
} catch (\Throwable $error) {
    error_log('Billions WHMCS callback: ' . $error->getMessage());
    billions_callback_reply(503, 'Payment verification failed');
}
