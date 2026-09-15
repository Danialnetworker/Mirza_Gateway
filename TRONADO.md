# راهنمای نصب و راه‌اندازی درگاه ترونادو — میرزا بات

<div dir="rtl">

با این نسخه از میرزا بات، فروشگاه شما می‌تواند پرداخت **کارت‌به‌کارت با ترونادو**
را بپذیرد: خریدار داخل مینی‌اپ ترونادو کارت‌به‌کارت می‌پردازد و شما **معادل آن را
به ترون (TRX)** در ولت خودتان دریافت می‌کنید. کارمزد ترونادو روی خریدار است؛ شما
دقیقاً همان مقدار ترونی را که فاکتور شده دریافت می‌کنید و ربات همان مبلغ تومانی
را به موجودی خریدار اضافه می‌کند — درست مثل بقیه‌ی درگاه‌ها.

---

## پیش‌نیازها

| مورد | توضیح |
|---|---|
| سرور | یک سرور **اوبونتو ۲۲.۰۴ یا ۲۴.۰۴ تمیز** (بدون وب‌سرور یا دیتابیسِ از پیش نصب‌شده) |
| دامنه | یک دامنه که به IP سرور اشاره کند |
| ربات تلگرام | **توکن ربات** از [@BotFather](https://t.me/BotFather) و **آیدی عددی ادمین** از [@userinfobot](https://t.me/userinfobot) |
| ترونادو | **کلید API** و **کلید امضای IPN** از پشتیبانی [@trndsupport](https://t.me/trndsupport) |
| ولت | یک آدرس **ولت ترون (TRC20)** که با `T` شروع می‌شود (۳۴ کاراکتر) — ترجیحاً فعال |

> 💡 درباره‌ی ولت: اگر ولت شما قبلاً هیچ تراکنشی نداشته باشد، **اولین** خریدار
> حدود **۱٫۲ ترون** بابت فعال‌سازی ولت روی شبکه اضافه می‌پردازد. با یک ولتِ از
> قبل فعال، این هزینه‌ی اضافه وجود ندارد.

---

## گام ۱ — نصب ربات روی سرور

با کاربر **root** وارد سرور شوید و این دستور را اجرا کنید:

```bash
curl -o install.sh -L https://raw.githubusercontent.com/tronadorobot/mirzabot/main/install.sh && bash install.sh
```

منوی زیر ظاهر می‌شود؛ گزینه‌ی **۱ (Install Mirza)** را بزنید:

```
1) Install Mirza
2) Update Mirza
3) Remove Mirza
...
```

سپس نصاب این موارد را از شما می‌پرسد:

- **توکن ربات** (از BotFather)
- **آیدی عددی ادمین**
- **دامنه** (مثلاً `bot.example.com`)
- **یوزرنیم و رمز دیتابیس** (هرچه بخواهید؛ نصاب خودش دیتابیس را می‌سازد)

نصب PHP، وب‌سرور، دیتابیس و SSL خودکار انجام می‌شود. در پایان، آدرس ربات به شما
داده می‌شود و ربات آماده است.

> برای نصب کاملاً خودکار (بدون منو) هم می‌توانید از خط فرمان استفاده کنید:
> ```bash
> mirza install --name نام‌ربات --token 123:ABC --admin 111111111 --domain bot.example.com --channel auto
> ```

---

## گام ۲ — اگر از قبل میرزا دارید (آپدیت)

همان دستور بالا را اجرا کنید و این بار گزینه‌ی **۲ (Update Mirza)** را انتخاب
کنید. گزینه‌ی «Automatic» آخرین نسخه‌ی این مخزن (شاملِ درگاه ترونادو) را نصب
می‌کند.

> ⚠️ حتماً از همین دستور (مخزن `tronadorobot`) آپدیت کنید. اگر از مخزن اصلی میرزا
> آپدیت کنید، درگاه ترونادو از روی ربات حذف می‌شود.

---

## گام ۳ — گرفتن کلیدها از پشتیبانی ترونادو

به [@trndsupport](https://t.me/trndsupport) پیام دهید و بگویید می‌خواهید درگاه
ترونادو را روی ربات فروش خود فعال کنید. برای شما یک حساب کسب‌وکار می‌سازند و این
دو مقدار را می‌دهند:

- **کلید API**
- **کلید امضای IPN**

آدرس کال‌بک خود را هم به آن‌ها بدهید تا (برای امنیت) در لیست مجاز ثبت کنند:

```
https://دامنه-شما/payment/tronado.php
```

> این مرحله برای یک حساب کاملاً جدید الزامی نیست (پیش‌فرض باز است)، اما برای
> امنیت توصیه می‌شود؛ جلوی سوءاستفاده در صورت لو رفتن کلید API را می‌گیرد.

---

## گام ۴ — فعال‌سازی درگاه در ربات ادمین

۱. در ربات، وارد پنل ادمین شوید → **تنظیمات پرداخت** → ردیف **📌 ترونادو** →
   دکمه‌ی **⚙️ تنظیمات**.

۲. این مقادیر را یکی‌یکی وارد کنید (روی هر دکمه بزنید و مقدار را بفرستید):

   - **🔑 کلید API ترونادو**
   - **🔏 کلید امضای IPN ترونادو**
   - **👛 ولت ترون (TRX)** — ترونِ هر پرداخت به این ولت واریز می‌شود
   - **⬇️ حداقل مبلغ** و **⬆️ حداکثر مبلغ** (به تومان؛ پیش‌فرض ۵۰٬۰۰۰ تا ۵۰۰٬۰۰۰)
   - **🎁 کش‌بک** (درصد؛ ۰ یعنی خاموش) — اختیاری
   - **📚 آموزش** — اختیاری؛ عکس/ویدئو/متنی که پیش از پرداخت به خریدار نشان داده می‌شود

۳. به ردیف ترونادو برگردید و آن را **روشن** کنید.

> دکمه‌ی پرداخت برای خریدار **فقط** زمانی ظاهر می‌شود که هر سه مقدار (کلید API،
> کلید IPN و ولت) ثبت شده باشند.

---

## گام ۵ — تست

یک خرید کوچک (بالاتر از حداقل مبلغ) انجام دهید، در مینی‌اپ ترونادو کارت‌به‌کارت
کنید و رسید بفرستید. اگر سرویس/موجودی خودکار تحویل داده شد و در کانال گزارش شما
`💵 پرداخت جدید` ثبت شد، درگاه سالم کار می‌کند.

---

## خریدار چه می‌بیند

در بخش «افزایش موجودی» یا هنگام خرید، دکمه‌ی **💳 پرداخت کارت به کارت با ترونادو**
را می‌زند، به مینی‌اپ ترونادو می‌رود، کارت‌به‌کارت می‌کند و رسید می‌فرستد. پس از
تأیید ترونادو، ربات به‌صورت خودکار سرویس یا موجودی را تحویل می‌دهد.

---

## رفع اشکال

اگر پرداختی شارژ نشد، ربات پیام `⭕️ پرداخت ترونادو … شارژ نشد: <دلیل>` را به کانال
گزارش می‌فرستد و **هیچ چیزی را خودکار واریز نمی‌کند** تا خودتان بررسی کنید:

| دلیل (در پیام، انگلیسی) | یعنی چه |
|---|---|
| `amount short` | مبلغِ تأییدشده در ترونادو کمتر از فاکتور بوده (کارت‌به‌کارتِ کمتر). خودتان تصمیم بگیرید چقدر شارژ شود. |
| `order record unsealed and toman paid below invoice` | رکورد سفارش قابل اعتماد نبوده و مبلغی که کاربر پرداخت کرده از فاکتور شما کمتر است. خودتان تصمیم بگیرید. |
| `order record unsealed and the payment is not to this shop's wallet` | رکورد سفارش قابل اعتماد نبوده و واریز به ولت دیگری رفته. دستی بررسی کنید. |
| `order record unsealed or tampered` | رکورد سفارش قابل اعتماد نبوده و راهی هم برای سنجیدن مبلغ نمانده (کال‌بک قدیمی بدون مبلغ تومانی). آن سفارش را دستی شارژ کنید. |
| `status check unavailable` | سرور شما لحظه‌ای به ترونادو نرسیده؛ ترونادو تا چند ساعت و کرونِ هر ۳ دقیقه دوباره تلاش می‌کنند و معمولاً خودش حل می‌شود. |

نکته‌های مهم:

- **عوض کردن کلید امضای IPN مشکلی برای سفارش‌های باز درست نمی‌کند.** مهرِ روی
  سفارش‌ها با کلید مخصوص خودِ ربات زده می‌شود، نه با کلید IPN؛ کلید قبلی هم یک
  نسل نگه داشته می‌شود. اگر باز هم مهر جور در نیاید، مبلغ پرداختی با فاکتور خودتان
  و ولت خودتان سنجیده می‌شود و در کانال گزارش هم بهتان اطلاع داده می‌شود.
- این جبران فقط برای **اولین** کال‌بکِ پرداختِ هر سفارش کار می‌کند. سفارشی که یک
  بار «شارژ نشد» گزارش شده، جواب ترونادو را گرفته و دیگر تلاش مجدد نمی‌شود و کرون
  هم ردش می‌کند؛ برای آن‌ها یک بار دکمه **ارسال مجدد IPN** را از مینی‌اپ ترونادو
  بزنید.
- **کرون** خودکار اضافه می‌شود؛ اولین باری که بعد از نصب یا آپدیت وارد پنل ادمین
  شوید، خط `*/3 * * * * php …/cronbot/tronado.php` ساخته می‌شود (از نسخه ۰.۵.۵
  کرون‌ها مستقیم با PHP اجرا می‌شوند و خط قدیمیِ `curl` خودکار پاک می‌شود).
  کارِ دستی لازم نیست.

برای هر مشکلی: [@trndsupport](https://t.me/trndsupport)

</div>

---

# Tronado gateway for Mirza Bot

This build of Mirza Bot ships the **Tronado** gateway: the buyer pays
card-to-card inside the Tronado mini app and the shop receives the
**equivalent in TRON (TRX)** in its own wallet. Tronado's fee is on the buyer;
the shop receives exactly the TRX it invoiced and credits the buyer its own
Toman price, like every other gateway.

## Requirements

1. A Tronado business account (@trndsupport) and, from them, an **API key** and
   an **IPN signing key**.
2. A TRON wallet address (TRC20, starts with `T`, 34 characters). Prefer one
   that is already activated (has had a transaction); an unactivated wallet
   makes the first buyer pay ~1.2 TRX extra for on-chain activation.
3. (Recommended) Give Tronado support your bot's domain so they whitelist the
   callback URL `https://your-domain/payment/tronado.php`. Not strictly
   required for a brand-new account (it is open by default), but recommended
   for security — it stops a leaked API key from redirecting your IPNs.

## Install

On a clean Ubuntu 22.04 / 24.04 server, as root:

```
curl -o install.sh -L https://raw.githubusercontent.com/tronadorobot/mirzabot/main/install.sh && bash install.sh
```

It is the stock Mirza installer, pointed at this repository for install and
update. An existing upstream install: run the same command, pick **Update**;
"Automatic" takes this repository's latest release (e.g. `0.4.1-tronado.1`).

## Shop setup (admin bot)

1. Admin bot → **Payment settings** → the **📌 Tronado** row → **⚙️ Settings**.
2. Set, one button at a time: **API key**, **IPN signing key**, **TRX wallet**,
   **min** / **max** amount (Toman; defaults 50,000–500,000), **cashback** (%,
   0 = off), optional **tutorial** media shown to the buyer.
3. Back on the Tronado row, switch it **on**. The buyer button appears only
   once API key, IPN key and wallet are all set.

## What the buyer sees

On top-up / purchase they tap **💳 Card-to-card via Tronado**, land in the
Tronado mini app, transfer card-to-card and upload the receipt. Once Tronado
accepts it, the bot delivers the balance/service automatically and posts
`💵 New payment` to your report channel.

## When a payment is not credited

The bot posts `⭕️ … not credited: <reason>` to the report channel and credits
nothing until a human looks. Common reasons:

| reason | meaning |
|---|---|
| `amount short (x of y TRX)` | the order was repriced on Tronado's side after creation (transfer smaller than the invoice). Decide the credit yourself. |
| `order record unsealed and toman paid below invoice (x of y)` | the order row could not be trusted and the buyer paid less than your invoice. Decide the credit yourself. |
| `order record unsealed and the payment is not to this shop's wallet` | the order row could not be trusted and the payment went somewhere else. Check it by hand. |
| `order record unsealed or tampered` | the order row could not be trusted and nothing is left to measure the payment against (an older callback with no toman figure). Credit that order by hand. |
| `status check unavailable` (in error_log) | your server could not reach Tronado at that moment; Tronado retries for hours and the cron re-checks open orders every 3 minutes. |

Rotating the IPN signing key is safe for open orders: they are sealed with the
bot's own key, not the IPN key, and the previous IPN key is kept for one
generation. If a seal still fails to verify, the payment is measured against
your own invoice and your own wallet instead, and the report channel tells you
it happened.

That rescue applies to the *first* paid callback for an order. Once an order has
already been reported as not credited, Tronado has been answered and stops
retrying, and the 3-minute cron skips flagged rows — so an order that failed
before you upgraded needs one manual **Resend IPN** from the Tronado mini app.

## Cron

The first time an admin opens the admin panel after updating, the bot adds
`*/3 * * * * php <bot dir>/cronbot/tronado.php` itself (since 0.5.5 Mirza runs
its crons through the PHP CLI and removes the old `curl https://your-domain/cronbot/…`
lines, the Tronado one included). Nothing to do.

## Source

Gateway code: `payment/tronado.php` (signed IPN receiver),
`cronbot/tronado.php` (poll fallback), the `tronado*` helpers in
`function.php`, buyer flow in `index.php`, admin settings in `admin.php`,
migration `db/migrations/009_tronado_gateway.php`. Licensed with the rest of
Mirza Bot (AGPL-3.0-or-later).
