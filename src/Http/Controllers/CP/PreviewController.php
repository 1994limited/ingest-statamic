<?php

namespace NineteenNinetyFour\Ingest\Http\Controllers\CP;

use Illuminate\Http\Request;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Preview\Preview;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;

/**
 * The live preview on the feed form: reads the first page of the feed as
 * it's set up in the form (saved or not) and maps the sample items.
 */
class PreviewController extends CpController
{
    public function __invoke(Request $request, Preview $preview)
    {
        $this->authorize('manage ingest feeds');

        $handle = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $request->input('handle'))) ?: 'preview';

        try {
            $feed = Feed::make($handle, (array) $request->input('feed', []));
            $result = $preview->fetch($feed, $request->boolean('fresh'));
        } catch (Throwable $e) {
            if (! $e instanceof IngestException) {
                report($e);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $result;
    }
}
