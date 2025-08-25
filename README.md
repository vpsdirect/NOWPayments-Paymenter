# NowPayments-Paymenter

## NowPayments Paymenter Extension

This is an extension for **Paymenter**, allowing merchants to integrate **NowPayments** as a payment gateway. The extension enables customers to pay using **cryptocurrencies** while automating **payment status updates**.

Sign up [here]([https://nowpayments.io](https://account.nowpayments.io/de/create-account?link_id=3682278049&utm_source=affiliate_lk&utm_medium=referral)) if you don't have a NowPayments account.

---

## 🚀 Features

- ✅ **Secure payment processing** via NowPayments
- ✅ **Automatic payment status updates** via IPN webhooks
- ✅ **Supports 200+ cryptocurrencies**
- ✅ **HMAC-SHA512 signature verification** for security
- ✅ **Sandbox mode** for testing
- ✅ **Works with Paymenter v1.0+**

---

## 📌 Installation

### 1️⃣ Download the Extension

1. Download the latest release from the [GitHub](https://github.com/vpsdirect/NOWPayments-Paymenter)
2. Move the downloaded files to the `extensions/Gateways/NOWPayments` directory of your Paymenter installation.

### 2️⃣ Configure Paymenter

Navigate to **Admin Panel → Settings → Extension Settings**, then:

1. **Enable** the NowPayments payment gateway
2. Enter your **API Key** (found in your NowPayments dashboard)
3. Enter your **IPN Secret Key** (set up in NowPayments IPN settings)
4. Set the **base currency** (e.g., `USD`, `EUR`, `GBP`)
5. Optionally enable **Sandbox Mode** for testing

### 3️⃣ Setup IPN Webhook

1. Log in to your **NowPayments account**
2. Go to **Settings → IPN Settings**
3. Enable **IPN Notifications**
4. Set your **IPN Callback URL** to:
   ```
   https://yourdomain.com/extensions/gateways/nowpayments/webhook
   ```
5. Generate and save your **IPN Secret Key**
6. Add this secret key to your Paymenter configuration

---

## 🛠️ Configuration Options

| Option | Description | Required |
|--------|-------------|----------|
| `api_key` | Your **NowPayments API Key** | ✅ |
| `ipn_secret` | Your **IPN Secret Key** for webhook verification | ✅ |
| `currency` | Base fiat currency (e.g., `USD`, `EUR`, `GBP`) | ✅ |
| `sandbox_mode` | Enable sandbox/test mode | ❌ |

---

## 🔄 Webhook Handling

NowPayments sends **IPN notifications** when **payment status** changes. This extension:

- ✅ **Verifies webhook signatures** using HMAC-SHA512:
  ```
  hash_hmac('sha512', $request_body, $ipn_secret)
  ```
- ✅ Processes payment statuses:
  - 🟢 `finished`, `confirmed` → Marks invoice as paid
  - 🟡 `waiting`, `confirming`, `sending` → Payment processing
  - 🟠 `partially_paid` → Logs partial payment
  - 🔴 `expired`, `failed`, `refunded` → Payment failed

---

## 💡 Troubleshooting

### 1️⃣ Webhook signature mismatch?
- Ensure your **IPN Secret Key** is correctly configured in both NowPayments and Paymenter
- Verify IPN is enabled in your NowPayments account

### 2️⃣ Payment not marked as completed?
- Check Paymenter logs:
  ```
  storage/logs/laravel.log
  ```
- Verify webhook requests in NowPayments dashboard → Payment History

### 3️⃣ Minimum amount errors?
- NowPayments has minimum payment amounts for each cryptocurrency
- The extension automatically checks these limits before creating payments

### 4️⃣ Testing payments?
- Enable **Sandbox Mode** in the configuration
- Use NowPayments sandbox environment for testing

### 5️⃣ Still having issues?
- Check NowPayments API status
- Verify your API key has the correct permissions
- Open a GitHub Issue

---

## 🔒 Security
- All webhooks are verified using HMAC-SHA512 signatures
- API keys are stored securely in Paymenter's configuration
- No sensitive data is logged

---

## 📝 License

This project is licensed under the MIT License.

---
