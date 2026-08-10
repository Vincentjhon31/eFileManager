<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * One message a day about what is waiting.
 *
 * A digest rather than a notification per document, deliberately. A records
 * office moves dozens of papers a day; a system that emails about every one of
 * them is filtered into a folder nobody opens within a fortnight, and then the
 * one message that mattered is lost with the rest. One message, once, listing
 * what is actually late, gets read.
 *
 * Sent only to people who can do something about it — see SendDeskDigests.
 *
 * The payload is plain arrays rather than Eloquent models. This runs on a
 * cron-driven queue where nobody is watching the output, and a queued
 * notification that throws on unserialize because a row moved underneath it is
 * a failure that would go unnoticed for days.
 *
 * @phpstan-type DigestLine array{id: int, tracking_no: string, subject: string, due_at: ?string}
 */
class DeskDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, DigestLine>  $mine  Assigned to this person, due soon or late.
     * @param  array<int, DigestLine>  $overdue  Late, held anywhere in their office.
     * @param  int  $incoming  Sent to the office, not yet signed for.
     * @param  int  $awaiting  Sent by the office, not yet signed for by anyone.
     * @param  bool  $includesOfficeSummary  False for staff who cannot receive.
     */
    public function __construct(
        public readonly array $mine,
        public readonly array $overdue,
        public readonly int $incoming,
        public readonly int $awaiting,
        public readonly bool $includesOfficeSummary,
    ) {}

    /**
     * Build the lines this notification carries from a set of documents.
     *
     * @param  Collection<int, Document>  $documents
     * @return array<int, DigestLine>
     */
    public static function lines(Collection $documents): array
    {
        return $documents->map(fn (Document $d) => [
            'id' => $d->getKey(),
            'tracking_no' => $d->tracking_no,
            'subject' => $d->subject,
            'due_at' => $d->due_at?->toIso8601String(),
        ])->values()->all();
    }

    /** @return array<int, string> */
    public function via(User $notifiable): array
    {
        // The in-app list is the reliable channel: no mail server, no
        // credentials, no valid address needed. Mail is the nudge that reaches
        // somebody who has not signed in today.
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting("Good morning, {$notifiable->name}.");

        if ($this->mine !== []) {
            $mail->line('**Assigned to you**');

            foreach ($this->mine as $line) {
                $mail->line($this->describe($line));
            }
        }

        if ($this->includesOfficeSummary) {
            if ($this->incoming > 0) {
                $mail->line("**{$this->incoming}** document(s) sent to your office are waiting to be received.");
            }

            if ($this->awaiting > 0) {
                $mail->line("**{$this->awaiting}** document(s) your office released have not been signed for yet.");
            }

            if ($this->overdue !== []) {
                $mail->line('**Overdue with your office**');

                foreach ($this->overdue as $line) {
                    $mail->line($this->describe($line));
                }
            }
        }

        return $mail
            ->action('Open my desk', route('desk'))
            ->salutation('— '.config('app.name').', '.config('lgu.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'summary' => $this->subjectLine(),
            'incoming' => $this->incoming,
            'awaiting' => $this->awaiting,
            'overdue_count' => count($this->overdue),
            'mine_count' => count($this->mine),
            'documents' => collect($this->mine)
                ->concat($this->overdue)
                ->unique('id')
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    public function subjectLine(): string
    {
        $parts = [];

        if ($late = count($this->mine) + count($this->overdue)) {
            $parts[] = "{$late} needing attention";
        }

        if ($this->includesOfficeSummary && $this->incoming > 0) {
            $parts[] = "{$this->incoming} to receive";
        }

        return $parts === []
            ? 'Your desk today'
            : 'Your desk today: '.implode(', ', $parts);
    }

    /** @param  DigestLine  $line */
    private function describe(array $line): string
    {
        return sprintf(
            '- %s — %s%s',
            $line['tracking_no'],
            $line['subject'],
            $line['due_at'] ? ' (due '.ph_datetime($line['due_at']).')' : '',
        );
    }
}
