<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Marketing\IngestMarketingEventsAction;
use App\Http\Controllers\Controller;
use App\Models\Idempotency;
use App\Models\Site;
use App\Support\Hmac\HmacVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MarketingEventController extends Controller
{
    public function __construct(
        private readonly IngestMarketingEventsAction $ingestEvents,
        private readonly HmacVerifier $verifier,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $keyId = (string) $request->header('X-Api-Key', '');
        $timestamp = (string) $request->header('X-Timestamp', '');
        $signature = (string) $request->header('X-Signature', '');
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($keyId === '' || $timestamp === '' || $signature === '') {
            return $this->fail(401, 'missing_headers', 'Отсутствуют обязательные заголовки подписи');
        }

        if ($idempotencyKey === '') {
            return $this->fail(422, 'missing_idempotency_key', 'Заголовок Idempotency-Key обязателен');
        }

        $credential = $this->verifier->resolve($keyId);

        if ($credential === null) {
            return $this->fail(401, 'unknown_credential', 'Неизвестный или неактивный API-ключ');
        }

        $secret = $this->verifier->secretFor($credential);

        if ($secret === null) {
            return $this->fail(500, 'credential_unusable', 'Невозможно прочитать секрет ключа');
        }

        if (! $this->verifier->hasScope($credential, 'inquiries')) {
            return $this->fail(403, 'insufficient_scope', 'Ключ не имеет доступа к этой операции');
        }

        $path = '/api/v1/events';
        $rawBody = $request->getContent();

        $result = $this->verifier->verify(
            $credential,
            $secret,
            $request->getMethod(),
            $path,
            $timestamp,
            $rawBody,
            $signature,
        );

        if (! $result['ok']) {
            return $this->fail(401, $result['code'], $result['error']);
        }

        $validated = validator($request->json()->all(), [
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event_key' => ['required', 'string', 'max:128'],
            'events.*.event_type' => ['required', 'string', 'max:64'],
            'events.*.path' => ['nullable', 'string', 'max:512'],
            'events.*.client_id' => ['nullable', 'string', 'max:255'],
            'events.*.yclid' => ['nullable', 'string', 'max:255'],
            'events.*.referrer' => ['nullable', 'string', 'max:512'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.utm' => ['nullable', 'array'],
            'events.*.metadata' => ['nullable', 'array'],
        ])->validate();

        $scope = 'event:'.$credential->key_id;
        $requestHash = hash('sha256', $scope.'|'.$idempotencyKey.'|'.$rawBody);

        $existing = Idempotency::query()
            ->where('scope', $scope)
            ->where('key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return $this->fail(422, 'idempotency_key_reuse', 'Ключ использован с другим содержимым запроса');
            }

            return response()->json($existing->response, $existing->http_status);
        }

        /** @var Site $site */
        $site = $credential->site;

        $outcome = $this->ingestEvents->execute($validated['events'], $site);

        $payload = [
            'accepted' => count($outcome['accepted']),
            'duplicates' => $outcome['duplicates'],
        ];

        Idempotency::query()->create([
            'scope' => $scope,
            'key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'http_status' => 202,
            'response' => ['data' => $payload],
        ]);

        return response()->json(['data' => $payload], 202);
    }

    private function fail(int $status, string $code, string $message): JsonResponse
    {
        Log::warning('api.events.fail', ['code' => $code]);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
