<?php

namespace Database\Factories;

use App\Enums\Confidentiality;
use App\Enums\DocumentStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 *
 * Builds a plausible registered document without going through
 * DocumentRoutingService, which is what you want for listing, visibility and
 * permission tests.
 *
 * For anything that asserts on the state machine itself — releasing, receiving,
 * recalling, tracking numbers — drive the service instead. A factory can create
 * combinations the service would refuse, and a test built on one of those
 * proves nothing about the running system.
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $now = now()->setTimezone(ph_tz());

        return [
            // TST marks these as synthetic. Real numbers come from
            // TrackingNumberGenerator and carry the registering office's code.
            'tracking_no' => sprintf(
                'BGB-TST-%04d-%02d-%04d',
                $now->year,
                $now->month,
                fake()->unique()->numberBetween(1, 999999),
            ),
            'reference_no' => null,
            'document_type_id' => DocumentType::factory(),
            'subject' => ucfirst(fake()->words(6, true)),
            'description' => fake()->optional()->paragraph(),

            'registering_department_id' => Department::factory(),
            // Same office by default: an internally originated document. The
            // closure reads the value resolved for the key above it.
            'origin_department_id' => fn (array $attributes) => $attributes['registering_department_id'],
            'origin_external_name' => null,

            'confidentiality' => Confidentiality::Internal,
            'status' => DocumentStatus::Draft,

            // A freshly registered document sits with the office that
            // registered it — never nowhere.
            'current_holder_department_id' => fn (array $attributes) => $attributes['registering_department_id'],
            'current_holder_user_id' => null,

            'due_at' => null,
            'closed_at' => null,
            'created_by' => User::factory(),
        ];
    }

    /** Registered by, originating from, and sitting with one office. */
    public function forOffice(Department $office): static
    {
        return $this->state(fn () => [
            'registering_department_id' => $office->getKey(),
            'origin_department_id' => $office->getKey(),
            'current_holder_department_id' => $office->getKey(),
        ]);
    }

    /** Came in from somewhere else — a province, an agency, a barangay. */
    public function from(Department $origin, ?string $senderName = null): static
    {
        return $this->state(fn () => [
            'origin_department_id' => $origin->getKey(),
            'origin_external_name' => $senderName,
        ]);
    }

    /** Currently charged to this office, and optionally to a named person. */
    public function heldBy(Department $office, ?User $user = null): static
    {
        return $this->state(fn () => [
            'current_holder_department_id' => $office->getKey(),
            'current_holder_user_id' => $user?->getKey(),
            'status' => DocumentStatus::Received,
        ]);
    }

    public function status(DocumentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** Personnel, legal or disciplinary. Restricted within the holding office. */
    public function confidential(): static
    {
        return $this->state(fn () => ['confidentiality' => Confidentiality::Confidential]);
    }

    /** Eligible for the public portal — which is not the same as published. */
    public function publicDisclosure(): static
    {
        return $this->state(fn () => ['confidentiality' => Confidentiality::Public]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Received,
            'due_at' => now()->subDays(3),
        ]);
    }

    public function dueIn(int $days): static
    {
        return $this->state(fn () => ['due_at' => now()->addDays($days)]);
    }
}
