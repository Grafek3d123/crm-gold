<?php

namespace App\Console\Commands;

use App\Integrations\AdvertisingApp\AdvertisingAppClient;
use App\Models\Outbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeliverOutboxCommand extends Command
{
    protected $signature = 'crm:outbox:deliver {--limit=50 : Сколько событий обработать за раз}';

    protected $description = 'Доставить накопившиеся события в рекламное приложение. Без конфигурации доставка не выполняется.';

    public function handle(AdvertisingAppClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->warn('Webhook рекламного приложения не сконфигурирован (ADVERTISING_APP_WEBHOOK_URL / ADVERTISING_APP_SIGNING_SECRET).');
            $this->line('События остаются в outbox и будут доставлены после согласования контракта.');

            $pending = Outbox::query()->where('status', Outbox::STATUS_PENDING)->count();
            $this->info("Ожидают доставки: {$pending}");

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $events = Outbox::query()
            ->where('status', Outbox::STATUS_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($events as $event) {
            $result = $client->deliver($event);

            if ($result['ok']) {
                $event->status = Outbox::STATUS_SENT;
                $event->sent_at = now();
                $event->attempts = $event->attempts + 1;
                $event->last_error = null;
                $event->save();

                $sent++;
                continue;
            }

            $event->attempts = $event->attempts + 1;
            $event->last_error = $result['message'];
            $event->save();

            // Логируем только код и id — без payload и secrets
            Log::warning('outbox.delivery_failed', [
                'outbox_id' => $event->id,
                'code' => $result['code'],
            ]);

            $failed++;
        }

        $this->info("Доставлено: {$sent}, ошибок: {$failed}");

        return self::SUCCESS;
    }
}
