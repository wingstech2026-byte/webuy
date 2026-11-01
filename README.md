# WiBuy mobile app (Flutter)

Android and iPhone app for WiBuy: "Buy and sell easy for Salone."
It uses the existing Laravel backend (`../wibuy`) through its REST API at `/api/v1`. It uses the same accounts (phone number + SMS code), the same database, the same cart, orders, mobile money payments (pawaPay) and rules as the website.

## What is in the app

| Screen | API |
| --- | --- |
| Home (categories, deals, popular, new, top shops) | `GET /home` |
| Search, sort, category pages, paging | `GET /products` |
| Product (photos, bulk prices, quantity, add to cart, buy now, save, WhatsApp share) | `GET /products/{slug}`, `POST /cart/{slug}`, `POST /wishlist/{slug}` |
| Shop | `GET /shops/{slug}` |
| Log in with phone + SMS code | `POST /auth/code`, `POST /auth/verify` (Sanctum token) |
| Cart, grouped by shop | `GET/PATCH/DELETE /cart...` |
| Checkout (district, delivery or pickup per shop, Orange Money / Afrimoney) | `POST /checkout/quote`, `POST /checkout` |
| Payment status (polls every 5 s; dev "simulate" buttons with the fake gateway) | `GET /checkouts/{id}`, `POST .../cancel`, `POST .../simulate` |
| Orders, order timeline, "I got my order", reviews | `GET /orders`, `POST /orders/{id}/confirm`, `POST /orders/{id}/review` |
| Saved products, account, log out | `GET /wishlist`, `GET /me`, `POST /auth/logout` |
| **Rewards tab**: points (exchange for coupons), daily check-in, spin wheel, WiBuy Farm, cut the price, lightning deals, free trials, WiBuy Pass, coupons, "Invite friends" | `GET /rewards` and the endpoints in `../wibuy/README.md` (Rewards and engagement) |
| Coupon picker at checkout (the best coupon is chosen first), WiBuy Pass free delivery | `POST /checkout/quote` with `coupon_id` |
| Product: lightning deal countdown, member price, "Help me cut the price", free trial | `flash_deal`, `member_price`, `price_cut_campaign_id`, `trial_campaign_id` on the product |

| **Business buying** (Account): business account status, requests for quotes, side-by-side quote comparison and accepting a quote | `GET /business`, `GET/POST /rfqs`, `GET /rfqs/{id}`, `POST /quotes/{id}/accept` |
| Order paid in parts: pay the balance, book an inspection and see its report | `POST /orders/{id}/milestones/{m}/pay`, `POST /orders/{id}/inspection`, `GET /payments/{id}` |
| Product: lead time, sample order, risk warnings, seller reliability, business price note | `lead_time_days`, `sample_price`, `risk_warnings`, `seller.reliability_score` |
| Shopping assistant (when switched on) | `POST /assistant`, `POST /assistant/rfq` |
| WiBuy Global imports: deposit, tracking steps, balance on arrival | `GET /global/products`, `GET/POST /global/orders`, `POST /global/orders/{id}/balance` |

| **My shop** (sellers, from Account): overview, orders (accept, reject, ship, deliver), products with photos, earnings and payouts, quote requests | `/seller/...` |
| Photos taken in the app (camera or gallery, resized before upload): problem reports, free trial reviews, inspection reports, business papers | multipart uploads (`ApiClient.postMultipart`) |
| Chat with WiBuy support (and sellers when buyer-seller chat is on), with photos | `/chat`, `/chat/{id}/messages` |
| Notifications list, with bell and chat badges on Home (refreshed every 30 s) | `/notifications`, `/notifications/counts` |

Photos use the `image_picker` package. iOS camera and photo permission texts are in `ios/Runner/Info.plist`. Phone push notifications still need Firebase; see `../wibuy/README.md` → Notifications.

The app sends a random `X-Device-Id` with every request, kept on the phone. It stops one person using many accounts for price cuts and referrals. Shares go through `POST /shares`, so a friend's open counts (extra spin, farm water).

Deep links: `wibuy://app/products/SLUG` opens a product (Android intent filter in `AndroidManifest.xml`). On the first start after installing, the app asks `GET /app/deferred-link` for the product the person was looking at on the website, and opens it. For `https://your-domain/p/...` links, add an Android App Links intent filter for your domain.

These stay on the website, and the app opens them in the browser: selling and the seller dashboard (including Promotions), admin, problem reports with photos, free trial reviews with photos, and chat.

## Backend address

The app reads the API address from `--dart-define=API_BASE_URL=...` (see `lib/config.dart`).

| Where the app runs | API_BASE_URL |
| --- | --- |
| Real phone on the same Wi-Fi | `http://<computer Wi-Fi IP>:8000/api/v1` (now `http://113.54.254.124:8000/api/v1`) |
| Android emulator | `http://10.0.2.2:8000/api/v1` |
| Production | `https://<your domain>/api/v1` |

Your Wi-Fi IP can change. To check it, run `ipconfig` and look under "Wireless LAN adapter Wi-Fi" for the IPv4 Address. If it changed, update it in `WiBuy.code-workspace` (launch configurations), in `lib/config.dart` (default), and in `../wibuy/.env` (`APP_URL`, `VITE_DEV_HOST`).

Plain `http` is allowed only in Android debug and profile builds (`android/app/src/debug|profile/AndroidManifest.xml`). Release builds need `https`. On iOS, `NSAllowsArbitraryLoads` in `ios/Runner/Info.plist` is for development only; remove it before an App Store release.

## Run it

1. Start the backend from `../wibuy`:
   ```
   php artisan serve --host=0.0.0.0 --port=8000
   php artisan queue:work
   ```
2. Plug in the Android phone with USB debugging on.
3. Run the app:
   ```
   flutter run --dart-define=API_BASE_URL=http://113.54.254.124:8000/api/v1
   ```

In local development nothing is really sent by SMS. The login screen shows the code, because the API returns `dev_code` when `APP_ENV=local` and `SMS_DRIVER=log`.

## Checks

```
flutter pub get
flutter analyze
flutter test
```

An iPhone build needs a Mac with Xcode (`flutter build ios`). The `ios/` folder is ready for that.
