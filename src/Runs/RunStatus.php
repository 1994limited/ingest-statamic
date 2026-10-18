<?php

namespace NineteenNinetyFour\Ingest\Runs;

enum RunStatus: string
{
    case Queued = 'queued';
    case Fetching = 'fetching';
    case Processing = 'processing';
    case Finalising = 'finalising';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    // Finished, but the "missing items" step was skipped by the safety threshold.
    case Aborted = 'aborted';
    case Failed = 'failed';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Undone = 'undone';

    public function active(): bool
    {
        return in_array($this, [self::Queued, self::Fetching, self::Processing, self::Finalising, self::Cancelling], true);
    }

    public function finished(): bool
    {
        return ! $this->active();
    }

    public function successful(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Queued'),
            self::Fetching => __('Fetching'),
            self::Processing => __('Processing'),
            self::Finalising => __('Finishing'),
            self::Completed => __('Completed'),
            self::CompletedWithErrors => __('Completed with errors'),
            self::Aborted => __('Stopped by safety check'),
            self::Failed => __('Failed'),
            self::Cancelling => __('Cancelling'),
            self::Cancelled => __('Cancelled'),
            self::Undone => __('Undone'),
        };
    }
}
