---
title: Add the Meta (Facebook and Instagram) Pixel
tags: meta pixel, facebook, instagram, conversions api, purchase, advertising, tracking
page: admin/settings/meta-pixel
---

The **Meta Pixel** tells Meta how visitors use your site, so you can measure and improve your Facebook and Instagram adverts. It stays off until the visitor accepts cookies, so the **Cookie Consent** service must be installed.

## Set it up

1. Open **Meta Pixel Settings** and enter your **Pixel ID**, the 15 or 16 digit number from Meta Events Manager.
2. Tick **Enabled**.
3. Press **Save Settings**.

It only loads on the live site, and is off by default until your site's design includes it.

## Shop events

- **Browser shop events** sends product views, add to basket and purchase from the visitor's browser, only after they accept cookies. Your site's design needs the matching event tags, so ask your developer.
- **Server Purchase (Conversions API)** also sends each paid order from the server, so a sale is counted even if the visitor never reaches the thank-you page. It needs a **Conversions API access token**: in Events Manager, open your pixel, then **Settings**, **Conversions API**, **Generate access token**.

Use the **Test event code** from Events Manager while checking it works.
