# WiBuy

**Buy and sell easy for Salone.**

WiBuy is an online marketplace for Sierra Leone. *Wi* means "we" in Krio. Shops, market traders, wholesalers and importers list products; buyers browse, add to cart and pay with **Orange Money** or **Afrimoney**. WiBuy holds the money, the seller delivers (or the buyer picks up), and the seller is paid only after the buyer confirms delivery, or automatically 3 days later if nobody reports a problem. WiBuy keeps a commission per order.

- Laravel 12, PHP 8.3, Blade, Tailwind CSS (Vite). Server-rendered, mobile-first, about 1.5 KB of JavaScript on normal pages.
- Payments: pawaPay (deposits, refunds and payouts to sellers), currency **SLE** with 2 decimals.
- Login by phone number and SMS code. No passwords.
- SQLite for development, PostgreSQL in production.
- Queues (database driver) for SMS, image processing, payment calls and chat broadcasts; the scheduler runs the background checks.

## How it works

1. **Browse.** Home (search, categories, Deals of the day, featured, new arrivals, top sellers), category and search pages with filters (price, seller location, verified sellers) and sorting (newest, price, best rated). Product pages have a gallery, wholesale price tiers, stock, the seller card, reviews, "Share on WhatsApp" and Open Graph tags for link previews.
2. **Cart and checkout.** The cart is grouped by seller. At checkout the buyer picks delivery (fee set by the seller per district) or pickup **per seller**, enters an address and a mobile money number, and sees items, delivery fees and the grand total. One checkout makes **one order per seller** but only **one mobile money prompt** for the total.
3. **Pay.** The payment page says "Approve the payment prompt on your phone" and refreshes every 5 seconds until paid or failed (no JavaScript needed).
4. **Seller.** Gets an SMS, accepts or rejects within 24 hours, marks the order shipped / ready for pickup (with a delivery note), then delivered.
5. **Buyer.** Confirms delivery (or reports a problem), then rates the seller and the products.
6. **Money.** On completion the seller's ledger is credited with *total minus commission*. Sellers request payouts of their available balance; an admin approves; WiBuy sends it with pawaPay.

### Order statuses

```
pending_payment -> paid -> accepted -> shipped -> delivered -> completed   (seller credited)
                     |                    \_________\___ disputed -> completed | refunded
                     +-> rejected / cancelled (not accepted in 24 h) -> refunded
pending_payment -> cancelled (payment failed or not paid in 15 minutes; stock released)
```

### Money rules

| Rule | Where |
|---|---|
| Amounts are `decimal(12,2)` and calculated with `bcmath` (`App\Support\Money`), never floats | everywhere |
| Commission = % of the order's **item subtotal** (delivery fees are not charged). Site-wide rate in *Admin → Settings*, per-seller override on the seller's admin page. The rate is stored on each order | `orders.commission_percent` |
| Stock is reserved when the checkout is created (`lockForUpdate` on the products, in id order, so it can never go below zero) and released if the payment fails or is not made in 15 minutes | `CheckoutService` |
| A payment that arrives after the hold expired revives the order if the stock is still there, otherwise it is refunded | `CheckoutService::settlePaid` |
| Seller rejects, or does not accept within 24 h: the buyer is refunded in full automatically | `OrderService` |
| Completed order (buyer confirms, or 3 days after shipped/delivered with no dispute): ledger credit of total minus commission | `LedgerService` |
| Open dispute: no completion, no credit, until an admin refunds the buyer or releases the money to the seller | `DisputeService` |
| Payout request takes the amount off the available balance straight away; rejected or failed payouts put it back | `PayoutService` |

The ledger (`ledger_entries`) records every credit and debit and is never edited. Available balance = sum of the ledger. Pending = what open orders will earn. Paid out = completed payouts.

## Local setup

Requirements: PHP 8.3 with `bcmath`, `curl`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo_sqlite`, `zip` (and `exif` to rotate phone photos); Composer; Node 20+.

```bash
composer install
cp .env.example .env              # Windows: copy .env.example .env
php artisan key:generate
touch database/database.sqlite    # Windows: type nul > database\database.sqlite
php artisan migrate --seed
php artisan storage:link
npm install
npm run build                     # or `npm run dev` while working on CSS/JS
php artisan serve
```

Open http://localhost:8000.

**Sample data** (`php artisan migrate:fresh --seed`): 1 admin, 5 approved sellers in Freetown, Bo and Kenema, 10 categories with subcategories, 40 products (many with wholesale tiers, several in "Deals of the day"), and some completed orders with reviews. Prices are examples.

| Who | Phone to log in with |
|---|---|
| Admin | `ADMIN_PHONE` (default `076 000 000`) |
| Sellers | `077 100 001` … `077 100 005` |
| Buyers | `076 200 001` … `076 200 003` |

### Logging in during development

With `SMS_DRIVER=log` no SMS is sent. The code is written to `storage/logs/laravel.log` and, when `APP_ENV=local`, also shown on the code page.

### Paying during development

With `PAYMENT_GATEWAY=fake` the payment page shows **Simulate approve** and **Simulate decline**. They run the same code as the real webhook. Refunds and payouts complete immediately. The fake gateway refuses to start when `APP_ENV=production`.

### Queue worker and scheduler

SMS, image resizing, payment calls and chat broadcasts run on the queue. Without a worker nothing is sent and pictures stay "processing":

```bash
php artisan queue:work --tries=3
```

The scheduler runs the background checks every 5 minutes. In development run it in a terminal; in production use cron (below):

```bash
php artisan schedule:work
```

| Command | Every | What it does |
|---|---|---|
| `wibuy:sync-payments` | 5 min | Re-checks deposits, refunds and payouts still pending after 2 minutes (in case a webhook was missed) |
| `wibuy:cancel-unpaid` | 5 min | Cancels checkouts not paid within 15 minutes and gives the stock back (asks the gateway first) |
| `wibuy:refund-unaccepted` | 5 min | Refunds paid orders the seller did not accept within 24 hours |
| `wibuy:complete-orders` | 5 min | Completes orders 3 days after shipped/delivered with no dispute, and credits the seller |
| `wibuy:prune-otp` | daily | Deletes old login codes |

`composer dev` starts the server, a queue listener, logs and Vite together (the `pail` log viewer needs `pcntl`, which Windows does not have; there, start the pieces in separate terminals).

### Tests

```bash
php artisan test
```

145 tests cover: phone numbers and money maths; OTP login (hashing, 10-minute expiry, 5 attempts, 5 sends per hour, rate limits); seller application, approval, rejection, verification and suspension; product creation with images (resizing, thumbnails, failed pictures) and wholesale tiers; cart rules; checkout with the fake gateway; a multi-seller cart creating separate orders with one payment; commission; stock reservation, release, expiry and late payments; webhook idempotency and never trusting the webhook body; the pawaPay request formats (deposits, refunds, payouts) with faked HTTP; auto-refund on rejection and after 24 hours; delivery confirmation crediting the ledger; auto-completion; payouts (request, minimum, approve, reject, failure); disputes; reviews and averages; search and filters; wishlist; support chat and channel authorization; and that every page renders.

## Phone numbers

Stored as `232` + 8 digits (`23276123456`). Input like `076123456`, `76123456` or `+232 76 123 456` is accepted; shown as `076 123 456`. See `App\Support\PhoneNumber` (unit tested).

## Pictures

Uploads (product photos up to 6, shop logos, dispute photos) are stored privately under a random name, then a queued job decodes and re-encodes them: longest side at most **1080 px** (logos 512 px) as ~72% JPEG, plus a **360 px** thumbnail. Re-encoding means only real pictures survive and EXIF data (including GPS) is removed. SVG is refused. Lists and galleries load thumbnails with `loading="lazy"`; the full picture loads only on the product page. WhatsApp previews use the product's first picture.

## Switching to the pawaPay sandbox (then live)

1. Create a pawaPay account and get a **sandbox API token**.
2. In `.env`:

   ```env
   PAYMENT_GATEWAY=pawapay
   PAWAPAY_BASE_URL=https://api.sandbox.pawapay.io
   PAWAPAY_API_TOKEN=your-sandbox-token
   PAWAPAY_OPERATOR_ORANGE=ORANGE_SLE
   PAWAPAY_OPERATOR_AFRIMONEY=AFRIMONEY_SLE
   ```

   Operator codes are in `config/payments.php`; confirm the codes enabled on your account.
3. In the pawaPay dashboard set the **deposit, refund and payout callback URLs** to `https://your-domain/webhooks/pawapay` (public; use a tunnel such as ngrok when testing locally).
4. `php artisan config:clear` (or `config:cache` in production) and restart the queue worker.
5. To go live, change `PAWAPAY_BASE_URL` to `https://api.pawapay.io` and use the production token.

How it behaves:

- Each deposit, refund and payout has its own **UUID** (`payments.provider_reference`), which pawaPay uses to ignore duplicates, so retrying a job is safe.
- `POST /webhooks/pawapay` is CSRF-exempt and **never trusts its body**: it only reads the `depositId` / `refundId` / `payoutId`, then re-fetches the real status from pawaPay and applies it. Every status change is idempotent, so the same callback can arrive any number of times.
- Refunds are per order (its full total) against the checkout's deposit. If a refund fails an admin can retry it from the order page; payouts that fail go back to the seller's balance.

**Important:** `App\Payments\PawaPayGateway` targets the pawaPay **v1** REST API (`/deposits`, `/refunds`, `/payouts`, `correspondent`, `MSISDN`). It was written from the public documentation and tested against faked HTTP responses only, **not against the live sandbox**. Before taking real money, run a sandbox deposit, refund and payout end to end. If your account uses the v2 API, only that one class needs to change.

## SMS

`SMS_DRIVER=log` writes messages to the log. `SMS_DRIVER=http` posts JSON `{"from", "to", "message"}` with a bearer token to `SMS_HTTP_URL` (see `config/sms.php` and `App\Sms\HttpSender`; adjust it if your provider expects a different payload). The default sender ID is **WIBUY** and must be registered with your provider.

Every message carries the WiBuy name and tagline: login code, new order (to the seller), payment received, order accepted, shipped / ready for pickup, delivered reminder, refund issued, payout sent (plus seller approved/rejected, payout rejected and dispute updates). SMS are queued with retries and can never break an order or a payment.

## Chat

- **Support:** every user has a conversation with the WiBuy team (*Menu → Chat*). Admins answer from *Admin → Support* with unread counts.
- **Ask the seller:** a placeholder page for now. Set `CHAT_DIRECT_ENABLED=true` to turn it into a private buyer–seller chat (with voice notes).
- Real-time with Laravel Reverb when it is running, otherwise the browser polls every 5 seconds. Messages are rate-limited (20 a minute); admins can delete reported messages and block users.

Run Reverb locally with `php artisan reverb:start`. Generate your own `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` in `.env`, then `npm run build` (the `VITE_REVERB_*` values are baked into the JavaScript).

## Mobile app API (`/api/v1`)

The Flutter app in `../wibuy_mobile` (Android and iPhone) uses this backend through a JSON API at `/api/v1` (`routes/api.php`, `app/Http/Controllers/Api/V1`). It uses the same users, database, services and rules as the website.

- **Login:** `POST /api/v1/auth/code` with `{phone}`, then `POST /api/v1/auth/verify` with `{phone, code, name?}`. This returns a Laravel Sanctum token; send it as `Authorization: Bearer <token>`. `POST /auth/logout` revokes the token for that phone only. In local development with `SMS_DRIVER=log`, the code also comes back as `dev_code`.
- **Public:** `meta`, `home`, `categories`, `products` (same filters and sorts as the website), `products/{slug}`, `shops/{slug}`.
- **Logged in:** `me`, `cart`, `checkout/quote`, `checkout`, `checkouts/{id}` (poll every 5 s), `orders`, `orders/{id}/confirm`, `orders/{id}/review`, `wishlist`.
- **Errors:** validation errors return 422 with `errors`, and marketplace rules (stock, minimum order, delivery area) return 422 with `message`. Another user's orders or checkouts return 404.
- **Stays on the website for now:** selling, the seller dashboard, admin, problem reports with photos and chat. The API returns links to those pages, such as `report_problem_url` and `web_url`.

Tests are in `tests/Feature/Api/V1`. In production, serve the API over HTTPS on the same domain. No extra configuration is needed: tokens are stored in `personal_access_tokens`.

## Rewards and engagement

All rewards are **coupons or WiBuy Points**. They only lower the price of an order and can never be withdrawn as cash. Nobody pays to play. WiBuy pays for coupons and WiBuy Pass free delivery, so sellers always get their full price. The order keeps `total` (the seller side) and `discount`, and the buyer pays `amount_paid`. Refunds return `amount_paid`.

Every amount and limit is set in **Admin → Rewards** (`/admin/rewards/settings`; defaults are in `app/Support/RewardSettings.php`). Spin prizes and odds, lightning deals, price cuts, farm missions and prizes, and free trials have their own admin pages.

- **Welcome pack:** at first login the person gets coupons unlocked at once, plus a locked bonus coupon. The bonus unlocks when a friend opens their shared link and signs up (`?ref=CODE` on the website, `referral_code` at app login). The friend also earns them points.
- **App install coupon:** given at the first app login if the person has no orders yet. Mobile web product pages show "Get Le X off your first order in the WiBuy app". It links to `/get-app`, which remembers the product (a deferred deep link) and opens the Play Store. On its first start, the app calls `GET /api/v1/app/deferred-link` and opens that product.
- **Help me cut the price:** the admin (or a seller under *Promotions*) sets a product's lowest price, a time limit and the cut size. A buyer starts a cut and shares `/cut/{token}`. Each friend who opens it and logs in cuts a random amount, once per product. Self-cuts are blocked, and so are accounts sharing a device (app `X-Device-Id` header or website cookie). At the lowest price the buyer gets a coupon for the difference on one unit.
- **Daily spin wheel:** one free spin a day, plus one per successful share (a share counts when someone else opens it), up to the daily limit. WiBuy Pass members get extra spins. The odds come from the admin's weights and are shown in the app.
- **Daily check-in:** points that grow with the streak.
- **Points:** exchanged for a coupon (for example 500 points = Le 5).
- **Lightning deals:** short deals with a price and a per-order limit. They are scheduled by the admin, or filled automatically every evening from products marked "deal" (`wibuy:rotate-deals`). "Remind me" sends an SMS when the deal starts.
- **WiBuy Farm:** a rice plant or mango tree, watered by missions: check-in, browsing deals, sharing, ordering, reviewing and watering friends (`/farm/{code}`). A fully grown plant gives the admin's prize: a big coupon, or a real gift that is attached to the next paid order (`gift_note`). The admin marks gifts as delivered and sees what prizes cost.
- **Free trials:** sellers offer units free or at a low price under *Promotions*. People apply, and the seller (or an admin) picks testers, who get a coupon. Testers must post an honest review with photos within the set number of days of receiving it (`/trials/{id}/review`). The review is labelled "Free trial". Reviews that never come become overdue and can block new trials.
- **WiBuy Pass:** one month at a time by mobile money (`POST /api/v1/pass`). It gives free delivery on shop orders from a set amount, the seller's member prices and extra spins. An SMS reminder goes out before it ends. It is never charged automatically.

API (all under `/api/v1`, logged in unless marked public):

| Area | Endpoints |
| --- | --- |
| Rewards hub, points and coupons | `GET rewards`, `POST rewards/check-in`, `GET coupons`, `GET points`, `POST points/exchange`, `POST shares` (`kind`: app, product, price_cut, farm) |
| Spin wheel | `GET spin` (prizes with odds, spins left), `POST spin` |
| Lightning deals | `GET lightning-deals` (public), `POST lightning-deals/{id}/remind` |
| Cut the price | `GET price-cuts`, `POST price-cuts` (`campaign_id`), `GET price-cuts/{token}` (public), `POST price-cuts/{token}/cut` |
| WiBuy Farm | `GET farm`, `POST farm/plant`, `GET farm/friends/{code}` (public), `POST farm/friends/{code}/water` |
| Free trials | `GET trials`, `POST trials/{id}/apply` |
| WiBuy Pass | `GET pass`, `POST pass`, `GET pass/{id}`, `POST pass/{id}/simulate` (fake gateway only) |
| App install | `GET app/deferred-link` (public) |
| Checkout | `POST checkout/quote` and `POST checkout` take an optional `coupon_id`. The quote returns `amount_due`, `coupon_discount`, `pass_savings` and the usable `coupons` |

Scheduler: `wibuy:rewards-tick` runs every 5 minutes (deal reminders, expiry), `wibuy:rotate-deals` daily at 20:00, and `wibuy:daily-rewards` daily at 09:00 (Pass reminders, trial deadlines). The seeder adds demo data for each feature. Tests are in `tests/Feature/Rewards`.

## Business buying

- **Business buyer accounts:** a buyer registers a business with its registration number and photos of the papers (`/business`; the app opens this page). An admin verifies it in *Admin → Businesses*. Only verified business buyers see and get **business-only wholesale tiers**: sellers tick "Only for verified business buyers" on a tier.
- **MOQ and lead time:** the minimum order quantity is enforced in the cart and at checkout. Made-to-order products show "Ready in X days" (`lead_time_days`).
- **Requests for quotation:** buyers post an RFQ (`POST /api/v1/rfqs`). Sellers answer under *Seller → Quote requests*, and a quote locks the price for a set number of days (14 by default). The buyer compares quotes side by side (`GET /api/v1/rfqs/{id}`: price, total with delivery to their district, days, rating and reliability) and accepts one (`POST /api/v1/quotes/{id}/accept`). That places an order at the quoted price and declines the other quotes.
- **Milestone payments:** orders from one shop above a set amount can be paid in parts (`payment_plan: milestones`): a deposit with the checkout (30% by default), then the balance after the seller accepts (`POST /api/v1/orders/{order}/milestones/{milestone}/pay`). The seller cannot ship until everything is paid. If the seller does not deliver, the buyer reports a problem, and when the dispute is decided for the buyer, each paid part is refunded from its own payment.
- **Samples:** a seller sets a `sample_price`. The buyer orders one unit (`sample: true` at checkout; the minimum order does not apply). When the sample order completes, the buyer gets the sample price back as a coupon for a bulk order of that product.
- **Delivery reliability score (0-100):** calculated every night by `wibuy:reliability` from the last 90 days: shipped on time (60%), not rejected or expired (25%) and no problem reports (15%). It is shown on the shop page and in the API. Text searches rank reliable shops first; the `reliable` sort is also available.
- **Pre-delivery inspection:** for orders paid in parts, the buyer pays an inspection fee (`POST /api/v1/orders/{order}/inspection`). An admin assigns a WiBuy agent in *Admin → Inspections*; agents are added there by phone number. The agent sends a pass or fail report with photos (`/agent/inspections`, or `POST /api/v1/agent/inspections/{id}/report`). The buyer can pay the last part only after a pass.
- **Risk warnings:** products priced below a set percentage of the category median, and sellers with no completed orders, carry `risk_warnings` (shown on the product page and in the app). In buyer-seller chat, a message asking to be paid outside WiBuy adds a safety warning to the conversation.
- **AI shopping assistant (`ASSISTANT_ENABLED=true` + `ANTHROPIC_API_KEY`):** `POST /api/v1/assistant` with `{message}`. The server calls the Claude API (model `ASSISTANT_MODEL`, default `claude-opus-5-5`) with a JSON schema to read the request: product, quantity, district, budget and date. It then searches WiBuy, returns a shortlist and an RFQ draft, and `POST /api/v1/assistant/rfq` turns the draft into an RFQ. The key stays on the server. Every request is logged in *Admin → AI assistant* with tokens, and each person has a daily limit.
- **WiBuy Global (`WIBUY_GLOBAL_ENABLED=true`):** in *Admin → WiBuy Global* the admin lists products with landed sea and air prices (goods, shipping and duties) and the deposit percentage. Buyers order and pay the deposit (`POST /api/v1/global/orders`). The admin moves each order along the steps (ordered, at the factory, shipped, at Freetown port, customs cleared, ready for pickup, collected), and the buyer gets an SMS at each step and pays the balance on arrival.

Balance payments, inspection fees and import payments are **charges**: mobile money payments of kind `charge` that point at what they pay for (`payable`). Poll them with `GET /api/v1/payments/{id}`. Limits and amounts are in *Admin → Rewards → Settings and limits* under "Business buying". Tests are in `tests/Feature/Business`.

## Seller tools, photos, chat and notifications in the app

- **Seller tools (`/api/v1/seller/...`):**
  - `dashboard`: counts, money and quote requests.
  - `orders?tab=new|accepted|shipped|delivered|problems|finished`, plus `orders/{id}/accept|reject|ship|deliver`.
  - `products`: create and update are multipart, `images[]` adds photos and `remove_images[]` removes them.
  - `earnings` and `payouts`.
  - `rfqs` and `rfqs/{id}/quote`.
  - They use the same requests, services and rules as the seller dashboard on the website.
- **Photos in the app (multipart):**
  - problem reports: `POST /orders/{id}/problem` with `photos[]`; `GET /problem-reasons` lists the reasons;
  - free trial reviews: `POST /trials/{id}/review`;
  - inspection reports: `POST /agent/inspections/{id}/report`;
  - business papers: `POST /business`.
- **Chat in the app** (the app checks for new messages every 5 seconds):
  - `GET /chat` lists the support chat and other chats;
  - `GET /chat/{id}/messages?after_id=` reads messages;
  - `POST /chat/{id}/messages` sends text and/or `image`.
  - Buyer-seller chat (`POST /chat/direct/{userId}`) stays behind `CHAT_DIRECT_ENABLED`. When it is off, "Chat about this product" opens WiBuy support.
- **Notifications:**
  - Every SMS WiBuy sends to a member (order, payment, deal, inspection, import and chat updates; never login codes) also appears in the app: `GET /notifications`, `GET /notifications/counts`, `POST /notifications/read`.
  - A notification about an order opens that order when tapped.
  - **Phone push notifications** use Firebase Cloud Messaging. The server side is ready: phones register with `POST /devices` (`token`, `platform`) and `SendPushJob` sends through the FCM HTTP v1 API. Dead tokens are removed.

  To switch push notifications on:
  1. Create a Firebase project at console.firebase.google.com and add an Android app with package `sl.wibuy.wibuy_mobile`.
  2. In *Project settings → Service accounts*, generate a private key (a JSON file). Store it outside the public folder.
  3. Set `FCM_PROJECT_ID=<project id>` and `FCM_CREDENTIALS=/full/path/to/key.json` in `.env`.
  4. In the Flutter app, add Firebase (`flutterfire configure`, then the `firebase_messaging` package) and send the token with `POST /api/v1/devices` after login. Until then the app shows notifications in its own list.

Tests are in `tests/Feature/Api/V1/SellerChatNotificationApiTest.php`.

## Admin panel (`/admin`)

Dashboard (sales today and this month, commission earned, new seller applications, open disputes, payouts to approve, failed refunds); users; seller applications (approve, reject with a reason, Verified badge, suspend, per-seller commission); categories and subcategories; products (suspend, feature in "Deals of the day" or on the home page); orders (with payments and refund retry); disputes (refund the buyer or pay the seller); payouts (approve or reject); settings (commission, minimum payout); support chat and reported messages.

## Security

- Form Request validation on every form; rate limits on OTP sending and checking, checkout, cart, disputes, payout requests and chat messages.
- Policies: buyers only see their own orders and checkouts, sellers only their own products, orders and disputes; `/seller` needs an approved shop and `/admin` needs an admin.
- OTP codes are hashed, expire after 10 minutes, allow 5 attempts and 5 sends per phone per hour.
- Pictures are re-encoded (see above); search input is escaped for SQL `LIKE`; Blade escapes all output.

## Deploying to a VPS (Ubuntu, Nginx, PHP-FPM, PostgreSQL, Supervisor, cron, HTTPS)

### 1. Packages

```bash
sudo apt update
sudo apt install -y nginx postgresql supervisor git unzip curl \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-bcmath php8.3-zip php8.3-gd php8.3-intl
# php8.3-exif is part of php8.3-common on Ubuntu. Composer: https://getcomposer.org  Node 20+: https://nodejs.org
```

### 2. Database

```bash
sudo -u postgres psql <<'SQL'
CREATE USER wibuy WITH PASSWORD 'change-me';
CREATE DATABASE wibuy OWNER wibuy;
SQL
```

### 3. Application

```bash
sudo mkdir -p /var/www/wibuy && sudo chown $USER: /var/www/wibuy
git clone <your-repo-url> /var/www/wibuy && cd /var/www/wibuy
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```

Edit `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wibuy.example.com
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=wibuy
DB_USERNAME=wibuy
DB_PASSWORD=change-me

QUEUE_CONNECTION=database
SMS_DRIVER=http                 # plus SMS_HTTP_URL and SMS_HTTP_TOKEN
PAYMENT_GATEWAY=pawapay         # plus the PAWAPAY_* settings above
COMMISSION_PERCENT=5
ADMIN_PHONE=076xxxxxx
```

```bash
npm ci && npm run build
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

The seeder fills the shop with **sample** sellers and products, so do not run it on a real launch. Create the first admin instead:

```bash
php artisan tinker --execute="App\Models\User::create(['name' => 'Your Name', 'phone' => '23276123456', 'is_admin' => true]);"
```

### 4. Nginx (`/etc/nginx/sites-available/wibuy`)

```nginx
server {
    listen 80;
    server_name wibuy.example.com;
    root /var/www/wibuy/public;
    index index.php;

    client_max_body_size 40M;   # up to 6 product photos per upload
    charset utf-8;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }

    location ~* \.(?:css|js|jpg|jpeg|png|webp|svg|ico)$ {
        expires 30d;
        access_log off;
    }
}
```

Also raise PHP's limits to match, in `/etc/php/8.3/fpm/php.ini`: `upload_max_filesize = 8M`, `post_max_size = 40M`, `memory_limit = 256M` (decoding large phone photos needs memory), then `sudo systemctl restart php8.3-fpm`.

```bash
sudo ln -s /etc/nginx/sites-available/wibuy /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 5. HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d wibuy.example.com
```

Certbot adds the HTTPS server block and renews automatically. pawaPay callbacks and WhatsApp previews need the public HTTPS URL.

### 6. Queue worker (Supervisor, `/etc/supervisor/conf.d/wibuy-worker.conf`)

```ini
[program:wibuy-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/wibuy/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/wibuy-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start 'wibuy-worker:*'
```

### 7. Scheduler (cron)

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/wibuy && php artisan schedule:run >> /dev/null 2>&1
```

### 8. Chat server (Reverb, optional)

Chat works without it (the browser polls every 5 seconds). For instant messages, set `REVERB_HOST=wibuy.example.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https`, `REVERB_SERVER_HOST=127.0.0.1`, `REVERB_SERVER_PORT=8080`, rebuild with `npm run build`, and add to the HTTPS server block:

```nginx
    location /app {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:8080;
    }
    location /apps {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_pass http://127.0.0.1:8080;
    }
```

and a Supervisor program running `php /var/www/wibuy/artisan reverb:start --host=127.0.0.1 --port=8080` as `www-data` (with `minfds=10000`).

### Deploying updates

```bash
cd /var/www/wibuy
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo supervisorctl restart 'wibuy-worker:*'
php artisan reverb:restart   # if you run Reverb
```

Back up the PostgreSQL database and `storage/app/public` (pictures) every day.

## Project layout

```
app/Support/        PhoneNumber, Money, ImageProcessor, Slug, Presence
app/Enums/          OrderStatus, CheckoutStatus, SellerStatus, ProductStatus, PaymentKind, PayoutStatus, LedgerType, DisputeStatus, ...
app/Models/         User, Seller, Category, Product, PriceTier, Image, Cart, Checkout, Order, Payment, Payout, LedgerEntry, Review, Dispute, ...
app/Services/       CheckoutService, OrderService, PaymentService, LedgerService, PayoutService, DisputeService,
                    CartService, SearchService, ProductService, SellerService, ReviewService, ImageService, OtpService, SmsService, ChatService
app/Payments/       PaymentGateway, FakeGateway, PawaPayGateway, GatewayResult
app/Sms/            SmsSender, LogSender, HttpSender, SmsMessages
app/Jobs/           InitiatePaymentJob, SyncPaymentJob, ProcessImageJob, SendSmsJob, NotifyOfflineParticipantsJob
app/Console/Commands/   sync payments, cancel unpaid, refund unaccepted, complete orders, prune OTP
app/Http/           public, Seller/ and Admin/ controllers, form requests, EnsureSeller and EnsureAdmin middleware
app/Policies/       OrderPolicy, ProductPolicy, DisputePolicy, CheckoutPolicy, ConversationPolicy
config/             wibuy.php, payments.php, sms.php, chat.php
```

## Known limits

- English only. Delivery fees are per district, not per distance or weight.
- Buyer–seller chat is off by default (`CHAT_DIRECT_ENABLED`).
- Row locking (`lockForUpdate`) does nothing on SQLite, so the stock protection only works under real concurrency on PostgreSQL (or MySQL). The test suite runs on SQLite and checks the logic, not parallel requests.
- On checkout, switching a seller between delivery and pickup reloads the totals with a few lines of JavaScript. Without JavaScript the total shown can be out of date, but the server always recalculates it, so the payment prompt is for the correct amount.
