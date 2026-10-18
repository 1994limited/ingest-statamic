<?php

namespace NineteenNinetyFour\Ingest\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Runs\AlreadyRunning;
use NineteenNinetyFour\Ingest\Runs\Runner;
use NineteenNinetyFour\Ingest\Webhooks\Token;

/**
 * Start a feed from outside: a supplier's system, a Google Apps Script
 * on a sheet, a deploy. POST /!/ingest/feeds/{handle}/run with the feed's
 * token in an "Authorization: Bearer …" or "X-Ingest-Token" header.
 *
 *   202  queued (the body has the run's id)
 *   401  no token or the wrong one (also for feeds without a webhook)
 *   409  the feed is already running
 *   423  the feed is paused
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $feed, FeedRepository $feeds, Runner $runner): JsonResponse
    {
        $token = $request->bearerToken() ?: (string) $request->header('X-Ingest-Token');
        $model = $feeds->find($feed);

        // The same answer whether or not the feed exists.
        if (! $model || ! Token::matches($model, $token)) {
            return response()->json(['message' => __('Unauthorised.')], 401);
        }

        if (! $model->enabled()) {
            return response()->json(['message' => __('The feed is paused.')], 423);
        }

        try {
            $run = $runner->start($model, 'webhook');
        } catch (AlreadyRunning $e) {
            return response()->json(['message' => $e->getMessage(), 'run' => $e->run?->id], 409);
        } catch (IngestException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => __('Queued.'), 'run' => $run->id, 'status' => $run->status->value], 202);
    }
}
