<?php

namespace NineteenNinetyFour\Ingest\Runs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One run of a feed: real or dry, its progress, what it did and why it
 * stopped.
 */
class Run
{
    public const COUNTS = [
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'skipped' => 0,
        'failed' => 0,
        'warnings' => 0,
        'conflicts' => 0,
        'unpublished' => 0,
        'deleted' => 0,
    ];

    public string $id;

    public string $feed;

    /** schedule, manual, cli, webhook, chain, apply */
    public string $trigger = 'manual';

    public bool $dryRun = false;

    public RunStatus $status = RunStatus::Queued;

    public ?string $user = null;

    /** @var array<string, int> */
    public array $counts = self::COUNTS;

    /** Items read from the feed, once known. */
    public ?int $total = null;

    public int $processed = 0;

    public ?string $message = null;

    /**
     * What was fetched: pages (relative file paths), the format, and the
     * validators (etag, last-modified, hash) for next time.
     *
     * @var array<string, mixed>
     */
    public array $payload = [];

    /** @var array<string, mixed> limit, apply (a dry run's id), force */
    public array $options = [];

    public ?CarbonImmutable $createdAt = null;

    public ?CarbonImmutable $startedAt = null;

    public ?CarbonImmutable $finishedAt = null;

    public ?CarbonImmutable $heartbeatAt = null;

    public static function make(string $feed, string $trigger = 'manual', bool $dryRun = false, array $options = []): self
    {
        $run = new self;
        $run->id = strtolower((string) Str::ulid());
        $run->feed = $feed;
        $run->trigger = $trigger;
        $run->dryRun = $dryRun;
        $run->options = $options;
        $run->createdAt = CarbonImmutable::now();

        return $run;
    }

    public function count(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function changed(): int
    {
        return $this->counts['created'] + $this->counts['updated'] + $this->counts['unpublished'] + $this->counts['deleted'];
    }

    public function duration(): ?int
    {
        return $this->startedAt && $this->finishedAt ? (int) $this->startedAt->diffInSeconds($this->finishedAt) : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'feed' => $this->feed,
            'trigger' => $this->trigger,
            'dry_run' => $this->dryRun,
            'status' => $this->status->value,
            'user' => $this->user,
            'counts' => $this->counts,
            'total' => $this->total,
            'processed' => $this->processed,
            'message' => $this->message,
            'payload' => $this->payload,
            'options' => $this->options,
            'created_at' => $this->createdAt?->toIso8601String(),
            'started_at' => $this->startedAt?->toIso8601String(),
            'finished_at' => $this->finishedAt?->toIso8601String(),
            'heartbeat_at' => $this->heartbeatAt?->toIso8601String(),
        ];
    }

    public static function fromArray(array $data): self
    {
        $run = new self;
        $run->id = $data['id'];
        $run->feed = $data['feed'];
        $run->trigger = $data['trigger'] ?? 'manual';
        $run->dryRun = (bool) ($data['dry_run'] ?? false);
        $run->status = RunStatus::tryFrom($data['status'] ?? '') ?? RunStatus::Failed;
        $run->user = $data['user'] ?? null;
        $run->counts = array_merge(self::COUNTS, (array) ($data['counts'] ?? []));
        $run->total = isset($data['total']) ? (int) $data['total'] : null;
        $run->processed = (int) ($data['processed'] ?? 0);
        $run->message = $data['message'] ?? null;
        $run->payload = (array) ($data['payload'] ?? []);
        $run->options = (array) ($data['options'] ?? []);

        foreach (['created_at' => 'createdAt', 'started_at' => 'startedAt', 'finished_at' => 'finishedAt', 'heartbeat_at' => 'heartbeatAt'] as $key => $property) {
            $run->{$property} = ! empty($data[$key]) ? CarbonImmutable::parse($data[$key]) : null;
        }

        return $run;
    }
}
