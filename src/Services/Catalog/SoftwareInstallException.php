<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog;

use Throwable;

/**
 * A software install that did not complete. The message is translation-backed and
 * user-facing; `touched` says whether live files were already replaced (the server is
 * then left stopped), `notes` carries what happened before the failure.
 */
final class SoftwareInstallException extends \RuntimeException
{
    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        string $message,
        public readonly bool $touched = false,
        public readonly array $notes = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
