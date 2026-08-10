<?php

namespace App\Enums;

/**
 * What a door on the floor map is telling you.
 *
 * This is the whole reason the building is worth drawing. A pretty picture of
 * the municipal hall is decoration; a picture where the Budget Office door has
 * been amber for three days is a status board for the entire LGU, readable at a
 * glance from across a room.
 *
 * The order below is the order of precedence: a room with one overdue document
 * and forty ordinary ones is Overdue.
 *
 * There is deliberately no "locked" state.
 *
 * A door's colour is a *count* — how many papers are waiting behind it — and a
 * count is aggregate operational information, not personal data. It is what a
 * stack of folders on a desk tells anyone walking past, and hiding it would
 * reduce the map to a picture of the building for everyone but one office. So
 * every signed-in employee sees every door's state.
 *
 * Reading the documents behind the door is a different question entirely, and
 * still answered the way it is everywhere else in this system: you can see a
 * document if it has passed through your office. Rooms the viewer cannot open
 * carry a lock mark; they are not greyed out.
 */
enum DoorState: string
{
    /** Nobody works here — the room is on the plan but no office is assigned. */
    case Vacant = 'vacant';

    /** Clear. Nothing waiting to be received and nothing on the desk. */
    case Idle = 'idle';

    /** Something is waiting: to be received, or sitting on a desk. */
    case Pending = 'pending';

    /** Something has passed its deadline. */
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Vacant => 'No office assigned',
            self::Idle => 'Clear',
            self::Pending => 'Work waiting',
            self::Overdue => 'Overdue',
        };
    }

    /**
     * The fill applied to the room shape.
     *
     * Spelled out as hex rather than Tailwind class names because these are
     * written into a generated stylesheet that targets element ids inside the
     * inlined SVG, where Tailwind's scanner would never find them.
     */
    public function fill(): string
    {
        return match ($this) {
            self::Vacant => '#f1f5f9',   // slate-100
            self::Idle => '#dbeafe',     // blue-100
            self::Pending => '#fde68a',  // amber-200
            self::Overdue => '#fecaca',  // red-200
        };
    }

    public function stroke(): string
    {
        return match ($this) {
            self::Vacant => '#cbd5e1',   // slate-300
            self::Idle => '#60a5fa',     // blue-400
            self::Pending => '#f59e0b',  // amber-500
            self::Overdue => '#dc2626',  // red-600
        };
    }

    /** Matches the badge tone vocabulary used everywhere else. */
    public function tone(): string
    {
        return match ($this) {
            self::Vacant => 'slate',
            self::Idle => 'blue',
            self::Pending => 'amber',
            self::Overdue => 'red',
        };
    }

    /**
     * Whether the door should draw the eye.
     *
     * Only overdue pulses. If amber pulsed too, a normal working day would have
     * half the building flashing and the animation would stop meaning anything.
     */
    public function shouldPulse(): bool
    {
        return $this === self::Overdue;
    }

    /** Decide a door's state from what is waiting behind it. */
    public static function decide(bool $hasOffice, int $waiting, int $overdue): self
    {
        return match (true) {
            ! $hasOffice => self::Vacant,
            $overdue > 0 => self::Overdue,
            $waiting > 0 => self::Pending,
            default => self::Idle,
        };
    }
}
