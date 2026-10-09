<?php

namespace App\Console\Commands;

use App\Attribution\AttributionProjectionService;
use Illuminate\Console\Command;

class RebuildAttributionCommand extends Command
{
    protected $signature = 'crm:rebuild-attribution {--customer= : Пересчитать только для одного клиента}';

    protected $description = 'Пересчитать проекции first/last touch из истории touchpoints.';

    public function handle(AttributionProjectionService $service): int
    {
        if ($customerId = $this->option('customer')) {
            $customer = \App\Models\Customer::query()->find($customerId);

            if ($customer === null) {
                $this->error("Клиент #{$customerId} не найден");

                return self::FAILURE;
            }

            $service->rebuildFor($customer);
            $this->info("Проекции для клиента #{$customerId} пересчитаны");

            return self::SUCCESS;
        }

        $count = $service->rebuildAll();
        $this->info("Пересчитано клиентов: {$count}");

        return self::SUCCESS;
    }
}
