<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The audit trail is the system's evidence. If it can be rewritten, it proves
 * nothing — so immutability is enforced at the model layer, not merely by the
 * absence of a UI for it.
 */
class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_audit_entry_cannot_be_updated(): void
    {
        $log = app(AuditLogger::class)->logAnonymous('test.event', description: 'Original');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $log->update(['description' => 'Rewritten']);
    }

    public function test_an_audit_entry_cannot_be_deleted(): void
    {
        $log = app(AuditLogger::class)->logAnonymous('test.event');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $log->delete();
    }

    public function test_the_original_value_is_intact_after_a_failed_rewrite(): void
    {
        $log = app(AuditLogger::class)->logAnonymous('test.event', description: 'Original');

        try {
            $log->update(['description' => 'Rewritten']);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('Original', $log->fresh()->description);
    }

    public function test_the_actor_and_their_office_are_captured_on_the_row(): void
    {
        $user = User::factory()->create();

        $log = app(AuditLogger::class)->log('test.event', $user, actor: $user);

        // Denormalised so the trail still reads correctly if the employee later
        // transfers to a different office.
        $this->assertSame($user->name, $log->actor_name);
        $this->assertSame($user->department_id, $log->department_id);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($user->id, $log->auditable_id);
    }

    public function test_entries_have_no_updated_at_column(): void
    {
        $log = app(AuditLogger::class)->logAnonymous('test.event');

        $this->assertNull(AuditLog::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }
}
