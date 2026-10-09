<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inquiries\IngestInquiryAction;
use App\Http\Controllers\Controller;
use App\Models\Idempotency;
use App\Models\Site;
use App\Support\Hmac\HmacVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InquiryController extends Controller
{
    public function __construct(
        private readonly IngestInquiryAction $ingestInquiry,
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

        $rawBody = $request->getContent();
        $path = '/api/v1/inquiries';

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
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'external_id' => ['nullable', 'string', 'max:128'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'landing_path' => ['nullable', 'string', 'max:512'],
            'referrer' => ['nullable', 'string', 'max:512'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'yclid' => ['nullable', 'string', 'max:255'],
            'utm' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ])->validate();

        $scope = 'inquiry:'.$credential->key_id;
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

        try {
            $inquiry = DB::transaction(fn () => $this->ingestInquiry->executeFromApi($validated, $site, $requestHash));
        } catch (RuntimeException $e) {
            return $this->fail(422, 'invalid_payload', $e->getMessage());
        }

        $payload = [
            'inquiry_id' => $inquiry->id,
            'customer_id' => $inquiry->customer_id,
            'status' => $inquiry->status->value,
            'site' => $site->code,
        ];

        Idempotency::query()->create([
            'scope' => $scope,
            'key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'http_status' => 201,
            'response' => ['data' => $payload],
        ]);

        return response()->json(['data' => $payload], 201);
    }

    private function fail(int $status, string $code, string $message): JsonResponse
    {
        Log::warning('api.inquiries.fail', ['code' => $code]);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
