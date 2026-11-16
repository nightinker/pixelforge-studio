<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /** How far along the lifecycle this status is. */
    private function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::Succeeded, self::Failed => 2,
        };
    }

    /**
     * Whether a generation in this status may move to $next.
     *
     * Webhooks arrive twice and out of order, so a status only ever moves
     * forward: a late `processing` must never undo `succeeded`, and a final
     * status never changes. Skipping ahead (queued -> succeeded) is fine,
     * because the `processing` event can simply arrive late or never.
     */
    public function canMoveTo(self $next): bool
    {
        return $next->rank() > $this->rank();
    }
}
