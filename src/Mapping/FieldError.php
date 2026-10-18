<?php

namespace NineteenNinetyFour\Ingest\Mapping;

use RuntimeException;

/**
 * One field couldn't be mapped. Depending on the feed, the rest of the
 * item is still saved (and this becomes a warning) or the item fails.
 */
class FieldError extends RuntimeException {}
