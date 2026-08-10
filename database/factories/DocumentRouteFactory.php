<?php

namespace Database\Factories;

use App\Enums\ActionRequested;
use App\Enums\ReceiptMethod;
use App\Enums\RouteStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRoute>
 *
 * Useful for desk and inbox query tests. As with DocumentFactory, do not use it
 * to test the state machine — it can produce a leg whose document status
 * disagrees with it, which the service would never allow.
 */
class DocumentRouteFactory extends Factory
{
    protected $model = DocumentRoute::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'seq' => 1,
            'from_department_id' => Department::factory(),
            'from_user_id' => User::factory(),
            'from_actor_name' => fake()->name(),
            'to_department_id' => Department::factory(),
            'to_user_id' => null,
            'action_requested' => ActionRequested::ForAppropriateAction,
            'remarks' => null,
            'is_return' => false,
            'due_at' => null,
            'sent_at' => now(),
            'status' => RouteStatus::Pending,
        ];
    }

    public function between(Department $from, Department $to): static
    {
        return $this->state(fn () => [
            'from_department_id' => $from->getKey(),
            'to_department_id' => $to->getKey(),
        ]);
    }

    /** Signed for. Receipt fields are set through the model, once. */
    public function received(?User $by = null, ReceiptMethod $method = ReceiptMethod::System): static
    {
        return $this->state(fn () => [
            'status' => RouteStatus::Received,
            'received_at' => now(),
            'received_by' => $by?->getKey(),
            'received_by_name' => $by?->name ?? fake()->name(),
            'receipt_method' => $method,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'sent_at' => now()->subDays(10),
            'due_at' => now()->subDays(3),
        ]);
    }
}
