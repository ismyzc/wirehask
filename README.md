# Netrova Analyzer Pro — Landing Page & Crypto Checkout

Single-page product site (English) with a $1,270 lifetime license sold via cryptocurrency.

## Files
- `index.html` — the full page (hero, features, protocols, pricing, FAQ, checkout modal).
- `config.js` — product name, price, sales email and **wallet addresses** (edit these before going live).
- `order.php` — optional PHP backend: live quotes, on-chain verification, order storage (`data/orders.json`), license key generation and email notifications.

## Payment methods
USDT (TRC20), USDT (ERC20), Bitcoin, Ethereum. BTC/ETH amounts use the live CoinGecko USD rate (locked for 30 minutes).

Payments are verified on-chain:
- USDT TRC20 → TronGrid
- BTC → mempool.space (requires 1 confirmation)
- ETH / USDT ERC20 → public Ethereum RPC (transaction receipt)

## Hosting
- **Static (GitHub Pages):** the page verifies payments directly in the browser; after confirmation the buyer sends the order to `sales_email` and you deliver the license.
- **PHP hosting (recommended for sales):** upload all files; `order.php` is detected automatically, orders are stored server-side and license keys are issued and emailed automatically. Make sure `data/` is writable and not publicly readable (`data/.htaccess` handles Apache; on Nginx add `location /data { deny all; }`).
