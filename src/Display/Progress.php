<?php

namespace Splicewire\Beam\Workflows\Display;

/**
 * Optional determinate progress on a {@see StatusEvent}: `{ done, total }`.
 *
 * Present iff the work is determinate — determinate work reports a percentage; indeterminate work
 * simply omits progress entirely (a null `progress` on the event). This is why it is a separate,
 * nullable value object rather than two nullable columns on the event.
 */
readonly class Progress
{
    public function __construct(
        public int $done,
        public int $total,
    ) {}

    public static function of(int $done, int $total): self
    {
        return new self($done, $total);
    }

    public static function fromArray(?array $data): ?self
    {
        if ($data === null || ! isset($data['done'], $data['total'])) {
            return null;
        }

        return new self((int) $data['done'], (int) $data['total']);
    }

    /**
     * Fraction complete in [0, 1]. A zero total is treated as complete (1.0) rather than dividing
     * by zero — an empty determinate batch is done, not undefined.
     */
    public function fraction(): float
    {
        if ($this->total <= 0) {
            return 1.0;
        }

        return min(1.0, max(0.0, $this->done / $this->total));
    }

    /**
     * @return array{done: int, total: int}
     */
    public function toArray(): array
    {
        return ['done' => $this->done, 'total' => $this->total];
    }
}
