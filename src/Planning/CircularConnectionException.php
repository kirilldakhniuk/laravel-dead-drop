<?php

declare(strict_types=1);

namespace DeadDrop\DeadDrop\Planning;

use RuntimeException;

/**
 * Thrown when the configured connections reference each other in a cycle, so
 * no order lets every cross-connection edge be followed against keys that are
 * already collected.
 */
final class CircularConnectionException extends RuntimeException {}
