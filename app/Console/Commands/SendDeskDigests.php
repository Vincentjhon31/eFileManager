<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;
use App\Notifications\DeskDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The morning round-up.
 *
 * Runs on a weekday schedule and tells each person what is waiting. Two rules
 * keep it from becoming noise, which is the only way a daily message survives:
 *
 *  - **Nothing to say, nothing sent.** A quiet desk gets no message at all.
 *  - **Only people who can act.** The office-wide summary goes to those who can
 *    receive documents — clerks, approvers, administrators. Everyone else hears
 *    only about papers assigned to them personally.
 */
class SendDeskDigests extends Command
{
    protected $signature = 'documents:send-desk-digests
                            {--due-within= : Days ahead to count a document as needing attention (default: the digest setting)}
                            {--dry-run : List who would be written to without sending anything}';

    protected $description = 'Send each employee a summary of what is waiting on their desk';

    public function handle(): int
    {
        // The option wins when given, so a one-off run can still be told what
        // to count; otherwise the municipality's setting decides.
        $dueWithin = max(0, (int) ($this->option('due-within') ?? config('digest.due_within', 2)));
        $dryRun = (bool) $this->option('dry-run');

        if (! config('digest.enabled', true)) {
            $this->info('The morning digest is switched off for the whole municipality (Settings → System).');

            return self::SUCCESS;
        }

        // Only offices actually using the system. Staff of an office that has
        // not onboarded have nothing to act on here, and their documents are
        // being received on paper by somebody else.
        $recipients = User::query()
            ->active()
            ->whereNotNull('department_id')
            ->whereHas('department', fn ($q) => $q->where('is_onboarded', true)->where('is_external', false))
            ->with('department')
            ->get();

        $sent = 0;

        foreach ($recipients as $user) {
            $digest = $this->digestFor($user, $dueWithin);

            if (! $digest) {
                continue;
            }

            $sent++;

            if ($dryRun) {
                $this->line(sprintf(
                    '  %-34s %s',
                    $user->email,
                    $digest->subjectLine(),
                ));

                continue;
            }

            $user->notify($digest);
        }

        $this->info(sprintf(
            '%s %d of %d employees.',
            $dryRun ? 'Would write to' : 'Queued digests for',
            $sent,
            $recipients->count(),
        ));

        return self::SUCCESS;
    }

    /** Null when this person's desk is clear and there is nothing worth saying. */
    private function digestFor(User $user, int $dueWithin): ?DeskDigest
    {
        $preferences = $user->preferences();

        // Somebody who has turned the digest off in Settings → Notifications
        // gets nothing, however full their desk is.
        if (! $preferences->wantsDigestEmail()) {
            return null;
        }

        $officeId = $user->department_id;

        // The office-wide figures need both the standing to see them and the
        // wish to: a clerk who only wants their own papers keeps the message
        // short, and for anyone who cannot receive there is no section anyway.
        $officeSummary = $user->can(Permission::DocumentsReceive->value)
            && $preferences->wantsOfficeSummary();

        /** @var Collection<int, Document> $mine */
        $mine = Document::query()
            ->visibleTo($user)
            ->where('current_holder_user_id', $user->getKey())
            ->dueBy($dueWithin)
            ->orderBy('due_at')
            ->limit(20)
            ->get();

        /** @var Collection<int, Document> $overdue */
        $overdue = $officeSummary
            ? Document::query()
                ->visibleTo($user)
                ->where('current_holder_department_id', $officeId)
                ->overdue()
                ->orderBy('due_at')
                ->limit(20)
                ->get()
            : new Collection;

        $incoming = $officeSummary ? DocumentRoute::awaitingReceiptBy($officeId)->count() : 0;
        $awaiting = $officeSummary ? DocumentRoute::releasedBy($officeId)->count() : 0;

        if ($mine->isEmpty() && $overdue->isEmpty() && $incoming === 0 && $awaiting === 0) {
            return null;
        }

        return new DeskDigest(
            mine: DeskDigest::lines($mine),
            overdue: DeskDigest::lines($overdue),
            incoming: $incoming,
            awaiting: $awaiting,
            includesOfficeSummary: $officeSummary,
        );
    }
}
