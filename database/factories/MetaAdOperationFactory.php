<?php

namespace Database\Factories;

use App\Models\MetaAdOperation;
use App\Models\Owner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaAdOperation>
 */
class MetaAdOperationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = [
            'daily_budget_minor' => fake()->numberBetween(100, 15000),
        ];

        return [
            'owner_id' => fn (): int => Owner::query()->create([
                'name' => fake()->company(),
                'type' => 'internal',
                'external_id' => fake()->unique()->uuid(),
            ])->id,
            'ad_account_id' => (string) fake()->numberBetween(10000000, 99999999),
            'type' => MetaAdOperation::TYPE_BOOST,
            'idempotency_key' => fake()->uuid(),
            'request_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'status' => MetaAdOperation::STATUS_PENDING,
            'request_payload' => $payload,
        ];
    }
}
