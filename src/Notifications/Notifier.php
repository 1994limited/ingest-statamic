<?php

namespace NineteenNinetyFour\Ingest\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use NineteenNinetyFour\Ingest\Settings;
use Throwable;

/**
 * Tells people when a feed goes wrong: by email (Settings or
 * INGEST_NOTIFY_MAIL) and Slack (INGEST_SLACK_WEBHOOK_URL).
 *
 * Once per problem, not once per run: a feed failing every 15 minutes
 * sends one message, then one more when it recovers.
 */
class Notifier
{
    private const EVENTS = [
        RunStatus::Failed->value => 'failed',
        RunStatus::Aborted->value => 'aborted',
        RunStatus::CompletedWithErrors->value => 'errors',
    ];

    public function runFinished(Feed $feed, Run $run): void
    {
        if ($run->dryRun) {
            return;
        }

        $on = (array) ($feed->get('notify.on') ?? []);
        $key = 'ingest:notified:'.$feed->handle();
        $problem = self::EVENTS[$run->status->value] ?? null;

        if ($problem !== null) {
            if (! in_array($problem, $on, true) || Cache::get($key) === $problem) {
                return;
            }

            Cache::forever($key, $problem);
            $this->send(
                __('Ingest: :feed :what', ['feed' => $feed->title(), 'what' => match ($problem) {
                    'failed' => __('failed'),
                    'aborted' => __('was stopped by its safety check'),
                    default => __('finished with errors'),
                }]),
                array_filter([
                    $run->message,
                    __(':created created, :updated updated, :failed failed.', $run->counts),
                ]),
                $this->url($run),
            );

            return;
        }

        if ($run->status->successful() && Cache::pull($key)) {
            $this->send(
                __('Ingest: :feed is working again', ['feed' => $feed->title()]),
                [__('The latest run finished: :created created, :updated updated.', $run->counts)],
                $this->url($run),
            );
        }
    }

    public function stale(Feed $feed, ?Run $lastSuccess, int $hours): void
    {
        $key = 'ingest:notified:stale:'.$feed->handle();

        if (Cache::get($key)) {
            return;
        }

        Cache::put($key, true, now()->addHours($hours));

        $this->send(
            __('Ingest: :feed hasn\'t updated for :hours hours', ['feed' => $feed->title(), 'hours' => $hours]),
            [$lastSuccess
                ? __('The last successful run was :when.', ['when' => $lastSuccess->finishedAt?->diffForHumans()])
                : __('It has never run successfully.')],
            null,
        );
    }

    /**
     * @param  string[]  $lines
     */
    private function send(string $subject, array $lines, ?string $url): void
    {
        $mail = Settings::list('notify_mail');

        if ($mail !== []) {
            try {
                Notification::route('mail', $mail)->notify(new RunNotification($subject, $lines, $url));
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($webhook = config('ingest.notifications.slack_webhook')) {
            try {
                Http::timeout(10)->post($webhook, [
                    'text' => "*{$subject}*\n".implode("\n", $lines).($url ? "\n<{$url}|".__('See the run').'>' : ''),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function url(Run $run): ?string
    {
        try {
            return cp_route('ingest.runs.show', [$run->feed, $run->id]);
        } catch (Throwable) {
            return null;
        }
    }
}
