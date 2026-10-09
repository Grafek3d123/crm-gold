<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Support\ContactNormalizer;

class CustomerMatchResult
{
    public function __construct(
        public readonly Customer $customer,
        public readonly bool $conflict = false,
        public readonly ?string $conflictNote = null,
    ) {}
}

class FindOrCreateCustomerAction
{
    public function execute(
        ?string $name,
        ?string $phoneRaw,
        ?string $emailRaw,
        ?string $comment = null,
    ): CustomerMatchResult {
        $phone = ContactNormalizer::phone($phoneRaw);
        $email = ContactNormalizer::email($emailRaw);

        if ($phone === null && $email === null) {
            throw new \RuntimeException('Не указан ни телефон, ни email для создания клиента');
        }

        $byPhone = $phone !== null ? $this->findByPhone($phone) : null;
        $byEmail = $email !== null ? $this->findByEmail($email) : null;

        if ($byPhone !== null && $byEmail !== null) {
            if ($byPhone->is($byEmail)) {
                return new CustomerMatchResult($byPhone);
            }

            // Конфликт: телефон у A, email у B. Не объединяем, не перезаписываем.
            return new CustomerMatchResult(
                $byPhone,
                true,
                sprintf('Конфликт matching: телефон уже у клиента #%d, email — у клиента #%d', $byPhone->id, $byEmail->id),
            );
        }

        if ($byPhone !== null) {
            return new CustomerMatchResult($this->fillMissing($byPhone, $email, $phoneRaw, $emailRaw));
        }

        if ($byEmail !== null) {
            return new CustomerMatchResult($this->fillMissing($byEmail, null, $phoneRaw, $emailRaw));
        }

        return new CustomerMatchResult($this->create($name, $phone, $email, $phoneRaw, $emailRaw, $comment));
    }

    private function findByPhone(string $phone): ?Customer
    {
        return Customer::query()
            ->where('phone_normalized', $phone)
            ->where('is_merged', false)
            ->first();
    }

    private function findByEmail(string $email): ?Customer
    {
        return Customer::query()
            ->where('email_normalized', $email)
            ->where('is_merged', false)
            ->first();
    }

    private function fillMissing(Customer $customer, ?string $email, ?string $phoneRaw, ?string $emailRaw): Customer
    {
        if ($email !== null && $customer->email_normalized === null) {
            $customer->email_normalized = $email;
            $customer->email_raw = $emailRaw;
        }

        if ($customer->phone_raw === null && $phoneRaw !== null) {
            $customer->phone_raw = $phoneRaw;
        }

        $customer->save();

        return $customer;
    }

    private function create(?string $name, ?string $phone, ?string $email, ?string $phoneRaw, ?string $emailRaw, ?string $comment): Customer
    {
        return Customer::query()->create([
            'name' => $name,
            'phone_normalized' => $phone,
            'email_normalized' => $email,
            'phone_raw' => $phoneRaw,
            'email_raw' => $emailRaw,
            'comment' => $comment,
            'is_merged' => false,
        ]);
    }
}
