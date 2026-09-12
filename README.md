<div align="center">

# Mirza Pro Max

**A Telegram bot that sells VPN subscriptions — and runs a different shop for every language you serve.**

Built on [Mirza Bot](https://github.com/mahdiMGF2/mirzabot), extended so one bot can behave like five.

<p>
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2"/>
  <img src="https://img.shields.io/badge/license-AGPL--3.0-blue?style=flat-square" alt="AGPL-3.0"/>
  <img src="https://img.shields.io/badge/Ubuntu%20%7C%20Debian-supported-E95420?style=flat-square&logo=ubuntu&logoColor=white" alt="Ubuntu / Debian"/>
  <img src="https://img.shields.io/badge/languages-5-success?style=flat-square" alt="5 languages"/>
</p>

</div>

---

## Install

One command, on a clean Ubuntu or Debian server, as root:

```bash
bash <(curl -Ls https://raw.githubusercontent.com/Alfred-1313/Mirza_Pro_Max/master/install.sh)
```

It installs Apache, PHP, MySQL and the bot, issues the SSL certificate and sets the Telegram webhook. Afterwards the same script is available as **`mirza`**:

```bash
mirza            # open the menu
mirza update     # pull the newest version
mirza --help     # every command and flag
```

**Before you start, have these ready:**

| You need | Where to get it |
| --- | --- |
| A domain pointed at this server | Your DNS provider — an `A` record to the server's IP, done *before* installing |
| A bot token | [@BotFather](https://t.me/BotFather) |
| Your numeric Telegram id | [@userinfobot](https://t.me/userinfobot) |
| A clean server | Ubuntu 20.04+ or Debian 11+, root access |

Prefer it unattended? Every answer can be a flag:

```bash
mirza install --channel release \
  --name myvpnbot --token 123456:ABC-DEF --admin 111222333 \
  --domain bot.example.com --db-user mirza --db-pass s3cret_1
```

---

## Already running the original Mirza?

You do not need to reinstall, and you will not lose anything. Run the same command above on your existing server and pick **Update**.

What the update does, in order:

1. Writes a full rollback archive to `/root/mirza-backups/` before touching a single file.
2. Downloads the release and refuses to continue if the package doesn't look like Mirza.
3. Copies the new files **over** your install — it never wipes the directory. Your `config.php`, your uploads, your `vendor/` and any file you added yourself stay exactly where they are.
4. Runs the database migration, which only ever **adds** missing tables and columns. No column is dropped, no row is rewritten. Every user, order, payment and setting survives.
5. Syntax-checks the core files, and rolls back automatically if anything is wrong.
6. Fixes the Apache vhost only when it is actually missing or wrong — a vhost you customised is backed up first, not overwritten.

If you ever want to undo an update:

```bash
tar -xzf /root/mirza-backups/pre-update_<timestamp>.tar.gz -C /var/www/html
```

> The database is **not** part of that archive. Take your own dump first if the install matters — menu option **6** does it and sends the file to Telegram.

---

## What this fork adds

The original ships one shop with one set of rules. This version makes almost everything **per language**, so a Persian customer and an English customer can be sold to on completely different terms by the same bot.

**🌐 Feature status, per language**
21 customer-facing switches — phone verification, rules acceptance, support in PV, bulk purchase, referral, wheel of fortune, location-change limits, config notes, app-download links and more — each set separately for `fa` / `en` / `ru` / `zh` / `tk`. Turning something off for English leaves Persian untouched. The screen itself redraws in whichever language's tab you open.

**Its own money, per language**
Amounts follow each language's currency from `lang_currency`, so the wheel prize reads `20,000 تومان` on the Persian tab and `$0.2` on the English one. Two-decimal currencies accept decimal input properly.

**Its own country, per language**
Phone verification enforces the dial code of the market it belongs to — `+98` for Persian, `+7` for Russian, `+86` for Chinese — instead of demanding an Iranian number from everyone. English is unrestricted by default, because it is a language and not a country.

**Inline settings screens**
The settings behind app links, wheel of fortune, referrals and location limits open inside the message you are already in, with a cancel button on every prompt. No more reply keyboards to get stuck in.

**Everything is rewordable**
Phone-verification, rules, wheel and referral messages — and their buttons — are editable per language from 🎨 شخصی‌سازی, alongside the messages that were already customisable.

**Other additions**
USDT (BEP20) gateway · numbered card placeholders for multi-card invoices · self-filtering service-location blocks · per-language gateway settings · grouped payment-gateway appearance editors.

---

## What it can talk to

**Panels** — Marzban · Marzneshin · X-UI (and single) · Hiddify · S-UI · WGDashboard · Mikrotik · IBSng · Alireza · Rebecca · VPNBot

**Payments** — card-to-card (with receipt review) · Zarinpal · AqayePardakht · IranPay ×3 · Plisio · NOWPayments · TRX · TON · USDT (BEP20) · DigitalTron · Telegram Stars

**Languages** — 🇮🇷 فارسی · 🇬🇧 English · 🇷🇺 Русский · 🇨🇳 中文 · 🇹🇲 Türkmençe

---

## Everyday commands

| Command | What it does |
| --- | --- |
| `mirza` | The menu |
| `mirza update` | Newest code + database migration, keeps your data |
| `mirza backup` | Database dump, delivered to Telegram |
| `mirza renew` | Reissue the SSL certificate |
| `mirza remove` | Remove the bot and its packages |

Useful paths:

```
/var/www/html/mirzaprobotconfig    the bot
  └─ config.php                    your credentials — never overwritten, never committed
/root/mirza-backups/               rollback archives from updates
/root/install.sh                   the management script itself
```

---

## Troubleshooting

**The bot doesn't answer.** Check the webhook first — `https://api.telegram.org/bot<TOKEN>/getWebhookInfo` should show your domain and no `last_error_message`.

**"Database connection failed".** The credentials live in `config.php`. Confirm MySQL is up with `systemctl status mysql`.

**A screen looks stale after an update.** Open `https://<your-domain>/table.php` once in a browser; that applies any migration the automatic step could not.

**SSL expired.** `mirza renew`.

---

## License and credit

This project is a fork of **[Mirza Bot](https://github.com/mahdiMGF2/mirzabot)** by [@mahdiMGF2](https://github.com/mahdiMGF2) and keeps its licence: **GNU AGPL-3.0**.

AGPL means that if you run a modified copy as a network service — and a Telegram bot is exactly that — you have to make your source available to its users. That is why this repository is public.

All original authorship and copyright remain with the upstream project and its contributors.
