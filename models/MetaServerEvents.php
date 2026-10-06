<?php

declare(strict_types=1);

namespace Osmium\Services\MetaPixel\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * Sends a Purchase to Meta's Conversions API when an order is paid.
 *
 * Two core hooks drive it (see the manifest):
 *   order.created - runs in the visitor's own request, so it can read their
 *                   cookies. It records whether they accepted cookies, plus
 *                   the Meta browser and click ids, against the order.
 *   order.paid    - may run in a payment webhook with no visitor present. It
 *                   sends only for orders the visitor consented on, once.
 *
 * Both are best-effort: core logs and skips a handler that throws, and a Meta
 * failure is stored on the order's context row and never blocks the sale.
 *
 * The browser's Purchase carries eventID = order_ref and this carries
 * event_id = order_ref, so Meta counts the sale once.
 */
class MetaServerEvents
{
    private const GRAPH_VERSION = 'v25.0';
    private const DEFAULT_CONSENT_COOKIE = 'osmium_cookie_consent';

    public static function orderCreated(array $payload): void
    {
        $config = MetaPixelConfig::get();
        if (!self::serverEventsActive($config)) return;

        /** @var OsmiumPDO $db */
        $db = $payload['dataSource'];

        $consentCookie = self::consentCookieName($db);
        $consented = ($_COOKIE[$consentCookie] ?? '') === 'accepted';

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $db->prepare(
            sql: "INSERT INTO {$db->tablePrefix()}meta_pixel_order_context (order_id, consent, fbp, fbc, event_source_url)
                  VALUES (:order_id, :consent, :fbp, :fbc, :url)
                  ON DUPLICATE KEY UPDATE consent = VALUES(consent), fbp = VALUES(fbp), fbc = VALUES(fbc), event_source_url = VALUES(event_source_url)",
            bindings: [
                ':order_id' => (int) $payload['orderId'],
                ':consent' => $consented ? 1 : 0,
                ':fbp' => self::cookie('_fbp'),
                ':fbc' => self::cookie('_fbc'),
                ':url' => $referer !== '' ? \substr($referer, 0, 500) : null,
            ],
        )->execute();
    }

    public static function orderPaid(array $payload): void
    {
        $config = MetaPixelConfig::get();
        if (!self::serverEventsActive($config)) return;

        /** @var OsmiumPDO $db */
        $db = $payload['dataSource'];
        $orderId = (int) $payload['orderId'];
        $table = "{$db->tablePrefix()}meta_pixel_order_context";

        $context = $db->prepare(sql: "SELECT * FROM {$table} WHERE order_id = :id", bindings: [':id' => $orderId])->single();
        if ($context === false) return; // Created before this service was installed or enabled

        $consented = (int) $context['consent'] === 1;
        $alreadySent = $context['capi_sent_at'] !== null;
        if (!$consented || $alreadySent) return;

        $result = self::send(config: $config, event: self::purchaseEvent(order: $payload['order'], context: $context));

        $db->prepare(
            sql: "UPDATE {$table} SET capi_sent_at = NOW(), capi_result = :result WHERE order_id = :id",
            bindings: [':id' => $orderId, ':result' => \substr($result, 0, 500)],
        )->execute();
    }

    /**
     * @param array<string, mixed> $order Core's order row with 'items'
     * @param array<string, mixed> $context This service's row for the order
     * @return array<string, mixed>
     */
    public static function purchaseEvent(array $order, array $context): array
    {
        $contents = [];
        foreach ($order['items'] as $item) {
            $contents[] = [
                'id' => (string) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'item_price' => \round((float) $item['unit_price_exc_tax'] * (1 + (float) $item['tax_rate_percent'] / 100), 2),
            ];
        }

        $event = [
            'event_name' => 'Purchase',
            'event_time' => \time(),
            'event_id' => (string) $order['order_ref'],
            'action_source' => 'website',
            'user_data' => self::userData(order: $order, context: $context),
            'custom_data' => [
                'value' => (float) $order['total_inc_tax'],
                'currency' => (string) $order['currency'],
                'content_type' => 'product',
                'content_ids' => \array_column($contents, 'id'),
                'contents' => $contents,
                'num_items' => \array_sum(\array_column($contents, 'quantity')),
            ],
        ];

        if (!empty($context['event_source_url'])) $event['event_source_url'] = $context['event_source_url'];

        return $event;
    }

    /**
     * Meta wants these hashed with SHA-256 after normalising; IP, user agent,
     * fbp and fbc go as they are.
     *
     * @return array<string, mixed>
     */
    public static function userData(array $order, array $context): array
    {
        [$first, $last] = self::splitName((string) ($order['customer_name'] ?? ''));

        $hashed = [
            'em' => self::normalise((string) ($order['customer_email'] ?? ''), keep: '/[^a-z0-9@._+\-]/'),
            'ph' => self::normalisePhone((string) ($order['customer_phone'] ?? '')),
            'fn' => $first,
            'ln' => $last,
            'ct' => self::normalise((string) ($order['billing_city'] ?? ''), keep: '/[^\p{L}\p{N}]/u'),
            'zp' => self::normalise((string) ($order['billing_postcode'] ?? ''), keep: '/[^a-z0-9]/'),
            'country' => \strtolower((string) ($order['billing_country'] ?? '')),
        ];

        $userData = [];
        foreach ($hashed as $key => $value) {
            if ($value !== '') $userData[$key] = [\hash('sha256', $value)];
        }

        if (!empty($order['ip_address'])) $userData['client_ip_address'] = $order['ip_address'];
        if (!empty($order['user_agent'])) $userData['client_user_agent'] = $order['user_agent'];
        if (!empty($context['fbp'])) $userData['fbp'] = $context['fbp'];
        if (!empty($context['fbc'])) $userData['fbc'] = $context['fbc'];

        return $userData;
    }

    /**
     * UK-focused: a leading 0 becomes 44. Anything else keeps its digits, so a
     * number typed with a country code (+34...) is not mangled.
     */
    public static function normalisePhone(string $phone): string
    {
        $digits = \preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') return '';

        $isNational = \str_starts_with($digits, '0') && !\str_starts_with($digits, '00');
        if ($isNational) return '44' . \substr($digits, 1);

        if (\str_starts_with($digits, '00')) return \substr($digits, 2);

        return $digits;
    }

    /**
     * @return array{0: string, 1: string} first and last name, normalised, '' when absent
     */
    public static function splitName(string $name): array
    {
        $parts = \preg_split('/\s+/', \trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) return ['', ''];

        $first = self::normalise($parts[0], keep: '/[^\p{L}]/u');
        $last = \count($parts) > 1 ? self::normalise($parts[\count($parts) - 1], keep: '/[^\p{L}]/u') : '';

        return [$first, $last];
    }

    private static function normalise(string $value, string $keep): string
    {
        $lower = \mb_strtolower(\trim($value));

        return \preg_replace($keep, '', $lower) ?? '';
    }

    private static function serverEventsActive(object $config): bool
    {
        $enabled = ($config->enabled ?? false) && ($config->serverEvents ?? false);
        $configured = MetaPixelConfig::isValidPixelId((string) ($config->pixelId ?? '')) && (string) ($config->accessToken ?? '') !== '';
        $isProduction = !\in_array(MetaPixelConfig::siteEnvironment(), ['dev', 'staging'], true);

        return $enabled && $configured && $isProduction;
    }

    private static function consentCookieName(OsmiumPDO $db): string
    {
        $context = (new ServiceLoader(new ServiceModel($db)))->serviceConfigContext(); // Cookie Consent's setting, read through core, not its PHP
        $name = (string) ($context['cookieConsent']['cookieName'] ?? '');

        return $name !== '' ? $name : self::DEFAULT_CONSENT_COOKIE;
    }

    private static function cookie(string $name): ?string
    {
        $value = (string) ($_COOKIE[$name] ?? '');

        return $value !== '' ? \substr($value, 0, 255) : null;
    }

    /**
     * @param array<string, mixed> $event
     * @return string "ok: <events_received>" or "error: <reason>", stored on the order's context row
     */
    private static function send(object $config, array $event): string
    {
        $body = [
            'data' => [$event],
            'access_token' => (string) $config->accessToken, // In the body, not the URL, so it stays out of logs
        ];
        $testCode = \trim((string) ($config->testEventCode ?? ''));
        if ($testCode !== '') $body['test_event_code'] = $testCode;

        $url = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . $config->pixelId . '/events';

        $curl = \curl_init($url);
        \curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = \curl_exec($curl);
        $status = (int) \curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = \curl_error($curl);
        \curl_close($curl);

        if ($response === false) return "error: {$curlError}";

        $decoded = \json_decode((string) $response, associative: true) ?? [];
        if ($status === 200) return 'ok: ' . (int) ($decoded['events_received'] ?? 0) . ' received';

        $message = (string) ($decoded['error']['message'] ?? 'unknown');

        return "error: HTTP {$status} {$message}";
    }
}
