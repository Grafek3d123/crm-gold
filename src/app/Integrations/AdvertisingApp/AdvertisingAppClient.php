<?php

namespace App\Integrations\AdvertisingApp;

use App\Models\Outbox;
use Illuminate\Support\Facades\Http;

/**
 * Граница доставки событий в рекламное приложение.
 *
 * Доставка остаётся выключенной, пока не подтверждены URL, схема payload
 * и способ авторизации. Без конфигурации job не шлёт запрос на догаданный
 * адрес — событие остаётся доступным, а причина недоставки диагностируемой
 * без утечки secrets.
 */
class AdvertisingAppClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.advertising_app.webhook_url'))
            && filled(config('services.advertising_app.signing_secret'));
    }

    /**
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public function deliver(Outbox $event): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'code' => 'not_configured',
                'message' => 'Webhook рекламного приложения не сконфигурирован',
            ];
        }

        $timestamp = (string) time();
        $body = json_encode($event->payload, JSON_UNESCAPED_UNICODE);

        $canonical = implode("\n", [
            $event->event_type,
            $timestamp,
            hash('sha256', $body),
        ]);

        $signature = hash_hmac(
            'sha256',
            $canonical,
            config('services.advertising_app.signing_secret')
        );

        try {
            $response = Http::withHeaders([
                'X-CRM-Timestamp' => $timestamp,
                'X-CRM-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])->withBody($body, 'application/json')
                ->timeout((int) config('services.advertising_app.timeout', 10))
                ->post((string) config('services.advertising_app.webhook_url'));
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'code' => 'transport_error',
                'message' => 'Ошибка соединения',
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'code' => 'http_'.$response->status(),
                'message' => 'Рекламное приложение отклонило доставку',
            ];
        }

        return ['ok' => true, 'code' => null, 'message' => null];
    }
}
