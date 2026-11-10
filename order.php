<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$raw  = (string)file_get_contents(__DIR__ . '/config.js');
$cfg  = json_decode(substr($raw, strpos($raw, '{'), strrpos($raw, '}') - strpos($raw, '{') + 1), true);
$cfg['notify_email'] = $cfg['sales_email'];
$cfg['from_email']   = $cfg['sales_email'];
$DATA = __DIR__ . '/data';

const USDT_TRC20_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
const USDT_ERC20_CONTRACT = '0xdac17f958d2ee523a2206206994597c13d831ec7';
const ERC20_TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
const ETH_RPC = 'https://ethereum-rpc.publicnode.com';

function out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d); exit; }

function http_json(string $url, ?array $post = null): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'NetrovaCheckout/1.0']);
    if ($post !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    $res = curl_exec($ch);
    $ok  = curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
    curl_close($ch);
    if (!$ok || !is_string($res)) return null;
    $j = json_decode($res, true);
    return is_array($j) ? $j : null;
}

function rates(string $dir): array {
    $f = "$dir/rates.json";
    if (is_file($f) && time() - filemtime($f) < 300) {
        $r = json_decode((string)file_get_contents($f), true);
        if (is_array($r)) return $r;
    }
    $j = http_json('https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum&vs_currencies=usd');
    if (!$j || empty($j['bitcoin']['usd']) || empty($j['ethereum']['usd'])) {
        $r = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        if (is_array($r)) return $r;
        out(['error' => 'Exchange rates are temporarily unavailable. Please try again.'], 503);
    }
    $r = ['btc' => (float)$j['bitcoin']['usd'], 'eth' => (float)$j['ethereum']['usd']];
    file_put_contents($f, json_encode($r));
    return $r;
}

function quote(array $cfg, string $dir): array {
    $r = rates($dir);
    $p = $cfg['price_usd'];
    $amounts = [
        'usdt_trc20' => number_format($p, 2, '.', ''),
        'usdt_erc20' => number_format($p, 2, '.', ''),
        'btc'        => number_format($p / $r['btc'], 8, '.', ''),
        'eth'        => number_format($p / $r['eth'], 6, '.', ''),
    ];
    $w = [];
    foreach ($cfg['wallets'] as $k => $v) $w[$k] = $v + ['amount' => $amounts[$k]];
    return ['product' => $cfg['product'], 'price_usd' => $p, 'wallets' => $w, 'rates' => $r];
}

function with_orders(string $dir, callable $fn) {
    $fh = fopen("$dir/orders.json", 'c+');
    flock($fh, LOCK_EX);
    $orders = json_decode((string)stream_get_contents($fh), true) ?: [];
    [$orders, $ret] = $fn($orders);
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    flock($fh, LOCK_UN); fclose($fh);
    return $ret;
}

function hexdec_big(string $hex): float {
    $hex = ltrim(preg_replace('/^0x/', '', $hex), '0');
    return $hex === '' ? 0.0 : (float)hexdec($hex);
}

/** Returns received amount in coin units, or null if not found / not confirmed. */
function verify(array $o, array $cfg): ?float {
    $addr = $cfg['wallets'][$o['coin']]['address'];
    $tx   = $o['tx'];
    switch ($o['coin']) {
        case 'usdt_trc20':
            $j = http_json("https://api.trongrid.io/v1/accounts/$addr/transactions/trc20?only_to=true&limit=200&contract_address=" . USDT_TRC20_CONTRACT);
            foreach ($j['data'] ?? [] as $t) {
                if (strcasecmp($t['transaction_id'] ?? '', $tx) === 0 && ($t['to'] ?? '') === $addr) {
                    return (float)$t['value'] / 1e6;
                }
            }
            return null;
        case 'btc':
            $j = http_json("https://mempool.space/api/tx/$tx");
            if (!$j || empty($j['status']['confirmed'])) return null;
            $sats = 0;
            foreach ($j['vout'] ?? [] as $v) if (($v['scriptpubkey_address'] ?? '') === $addr) $sats += (int)$v['value'];
            return $sats ? $sats / 1e8 : null;
        case 'eth':
            $t = http_json(ETH_RPC, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_getTransactionByHash', 'params' => [$tx]])['result'] ?? null;
            $r = http_json(ETH_RPC, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'eth_getTransactionReceipt', 'params' => [$tx]])['result'] ?? null;
            if (!$t || !$r || ($r['status'] ?? '') !== '0x1') return null;
            if (strtolower($t['to'] ?? '') !== strtolower($addr)) return null;
            return hexdec_big($t['value']) / 1e18;
        case 'usdt_erc20':
            $r = http_json(ETH_RPC, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_getTransactionReceipt', 'params' => [$tx]])['result'] ?? null;
            if (!$r || ($r['status'] ?? '') !== '0x1') return null;
            $sum = 0.0;
            $toTopic = '0x' . str_pad(substr(strtolower($addr), 2), 64, '0', STR_PAD_LEFT);
            foreach ($r['logs'] ?? [] as $l) {
                if (strtolower($l['address']) === USDT_ERC20_CONTRACT && ($l['topics'][0] ?? '') === ERC20_TRANSFER_TOPIC
                    && strtolower($l['topics'][2] ?? '') === $toTopic) {
                    $sum += hexdec_big($l['data']) / 1e6;
                }
            }
            return $sum ?: null;
    }
    return null;
}

function license_key(): string {
    $c = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $parts = [];
    for ($i = 0; $i < 4; $i++) { $s = ''; for ($j = 0; $j < 5; $j++) $s .= $c[random_int(0, 31)]; $parts[] = $s; }
    return 'NTRV-' . implode('-', $parts);
}

function notify(array $o, array $cfg): void {
    if (!function_exists('mail')) return;
    $h = 'From: ' . $cfg['from_email'];
    @mail($cfg['notify_email'], "[{$cfg['product']}] Order {$o['id']} – {$o['status']}",
        "Order: {$o['id']}\nStatus: {$o['status']}\nName: {$o['name']}\nEmail: {$o['email']}\nCoin: {$o['coin']}\nAmount: {$o['amount']}\nTX: {$o['tx']}\n", $h);
    if ($o['status'] === 'paid') {
        @mail($o['email'], "Your {$cfg['product']} license",
            "Hi {$o['name']},\n\nThank you for your purchase.\n\nOrder ID: {$o['id']}\nLicense key: {$o['license']}\n\nKeep this email for your records.\n", $h);
    }
}

function check(array $o, array $cfg): array {
    if ($o['status'] === 'paid') return $o;
    $got = verify($o, $cfg);
    if ($got !== null) {
        $o['received'] = $got;
        if ($got >= (float)$o['amount'] * $cfg['tolerance']) {
            $o['status'] = 'paid';
            $o['license'] = license_key();
            $o['paid_at'] = gmdate('c');
        } else {
            $o['status'] = 'underpaid';
        }
    }
    $o['checked_at'] = gmdate('c');
    return $o;
}

function public_view(array $o): array {
    return array_intersect_key($o, array_flip(['id', 'status', 'coin', 'amount', 'received', 'license', 'tx']));
}

$action = $_GET['action'] ?? '';

if ($action === 'quote') out(quote($cfg, $DATA));

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in    = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $name  = trim(mb_substr((string)($in['name'] ?? ''), 0, 100));
    $email = trim((string)($in['email'] ?? ''));
    $coin  = (string)($in['coin'] ?? '');
    $tx    = trim((string)($in['tx'] ?? ''));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) out(['error' => 'Please enter your name and a valid email.'], 422);
    if (!isset($cfg['wallets'][$coin])) out(['error' => 'Unsupported payment method.'], 422);
    $txOk = $coin === 'usdt_trc20' || $coin === 'btc' ? preg_match('/^[0-9a-fA-F]{64}$/', $tx) : preg_match('/^0x[0-9a-fA-F]{64}$/', $tx);
    if (!$txOk) out(['error' => 'The transaction hash format is not valid for this network.'], 422);

    $q = quote($cfg, $DATA);
    $order = with_orders($DATA, function (array $orders) use ($name, $email, $coin, $tx, $q) {
        foreach ($orders as $o) if (strcasecmp($o['tx'], $tx) === 0) out(['error' => 'This transaction has already been submitted.', 'order' => public_view($o)], 409);
        $o = ['id' => 'NTR-' . strtoupper(bin2hex(random_bytes(4))), 'created_at' => gmdate('c'), 'name' => $name, 'email' => $email,
              'coin' => $coin, 'amount' => $q['wallets'][$coin]['amount'], 'price_usd' => $q['price_usd'], 'tx' => $tx,
              'status' => 'pending', 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''];
        $orders[] = $o;
        return [$orders, $o];
    });
    $checked = check($order, $cfg);
    with_orders($DATA, function (array $orders) use ($checked) {
        foreach ($orders as $i => $o) if ($o['id'] === $checked['id']) $orders[$i] = $checked;
        return [$orders, null];
    });
    notify($checked, $cfg);
    out(['order' => public_view($checked)]);
}

if ($action === 'status') {
    $id = (string)($_GET['id'] ?? '');
    $res = with_orders($DATA, function (array $orders) use ($id, $cfg) {
        foreach ($orders as $i => $o) {
            if ($o['id'] === $id) {
                $before = $o['status'];
                $orders[$i] = check($o, $cfg);
                if ($before !== $orders[$i]['status']) notify($orders[$i], $cfg);
                return [$orders, $orders[$i]];
            }
        }
        return [$orders, null];
    });
    $res ? out(['order' => public_view($res)]) : out(['error' => 'Order not found.'], 404);
}

out(['error' => 'Bad request'], 400);
