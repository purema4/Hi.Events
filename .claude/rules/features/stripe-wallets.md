---
paths:
  - "frontend/src/components/forms/StripeCheckoutForm/**"
  - "backend/app/Services/Domain/Payment/Stripe/**"
  - "backend/app/Console/Commands/*Stripe*"
---

# Stripe wallets (Apple Pay / Google Pay / Link)

- Checkout renders the Stripe Express Checkout Element above the Payment Element, and wallets are set to `never` inside the Payment Element so the buttons aren't duplicated.
- Wallet buttons only render on domains registered with Stripe, and with direct charges the domain must be registered **on each connected account**. `StripePaymentMethodDomainRegistrationService` does this when an organizer's Connect setup completes; `php artisan stripe:register-payment-method-domains` backfills existing accounts and the platform account (run it after the frontend domain changes).
