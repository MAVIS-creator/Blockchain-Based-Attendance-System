<?php
require_once __DIR__ . '/vendor/autoload.php';

use Web3\Web3;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use Web3p\EthereumTx\Transaction;

function sendLogHashToPolygon($logFilePath)
{
    // Load config from environment or config file
    $privateKey = getenv('POLYGON_PRIVATE_KEY');
    $fromAddress = getenv('POLYGON_ADDRESS');
    $rpcUrl = getenv('POLYGON_RPC_URL');

    if (!$privateKey || !$fromAddress || !$rpcUrl) {
        throw new Exception("Polygon config missing.");
    }

    // 1. Hash the log file
    if (!file_exists($logFilePath)) {
        throw new Exception("Log file not found: $logFilePath");
    }
    $hash = hash_file('sha256', $logFilePath);

    // 2. Set up web3
    $web3 = new Web3(new HttpProvider(new HttpRequestManager($rpcUrl, 60)));

    // 3. Get nonce
    $nonce = null;
    $web3->eth->getTransactionCount($fromAddress, function ($err, $count) use (&$nonce) {
        if ($err !== null) throw $err;
        $nonce = $count->toString();
    });

    // 4. Prepare transaction
    $tx = [
        'nonce' => '0x' . dechex($nonce),
        'from' => $fromAddress,
        'to' => $fromAddress, // send to self
        'gas' => '0x5208', // 21000
        'gasPrice' => '0x' . dechex(20000000000), // 20 Gwei
        'value' => '0x0',
        'data' => '0x' . bin2hex($hash)
    ];

    // 5. Sign and send transaction
    $transaction = new Transaction($tx);
    $signedTx = '0x' . $transaction->sign($privateKey);

    $txHash = null;
    $web3->eth->sendRawTransaction($signedTx, function ($err, $result) use (&$txHash) {
        if ($err !== null) throw $err;
        $txHash = $result;
    });

    return $txHash;
} 