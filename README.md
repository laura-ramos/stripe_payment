# Stripe Payment

## Introduction
The main purpouse of this module is process payment with Stripe, Stripe allows to accept Visa, Mastercard, American Express, Discover, JCB, Diners Club, China UnionPay and debit cards, can also accept payments from mobile wallets and buy now, pay later services. Currently is a work in progress.

## Requirements
This module requires module PPSS.

## Installation
- Install as you would normally install a contributed Drupal module. See Installing Modules for more details.
- Install the Stripe PHP SKD by running composer require stripe/stripe-php
- Register to Stripe if you haven't yet

## Configuration

- **Create Account in Stripe**

  Go to Stripe https://dashboard.stripe.com/register

- **Configure stripe in drupal**

  Go to Configuration » Bigin » Plataforma de Pagos y Ventas Simple » Stripe Settings
  - Choose the **The content types**
    On the specified node types, an button will be available and can be shown to make purchases.
  - Enter the **Sandbox mode**
    Stripe’s test mode allows you to test your integration without making actual charges or payments.
  - Enter the **Public key** (optional)
    Public key to authenticate client-side.
  - Enter the **Secret key**
    Secret key to authenticate API requests.

    **You can find your secret and publishable keys on the API keys page in the Developers Dashboard.**

  - Enter the **Cancel url**
    Stripe redirects to this page when the customer clicks the back button in Checkout.

  Fields configuration:
  - Enter the **Price field name**
    Name of the field to store subscription price id.

  - Enter the **User role field name**
    Name of the field to store new user role assigned after purchased a plan.
  - Click Save configuration.

## How it works
