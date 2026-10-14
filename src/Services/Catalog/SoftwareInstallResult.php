<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog;

/**
 * Outcome of one successful {@see SoftwareInstallerService::install()} run.
 *
 * `notes` lists every adjustment made or skipped on the way (build fallback, renamed
 * runnable, Docker image / startup command changes, a failed autostart…) in the order
 * they happened, ready for the task card and the final notification. `attention` is set
 * when one of them asks the user to act (an image or startup change that was skipped, a
 * substituted build, a failed autostart).
 */
final class SoftwareInstallResult
{
    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $type,
        public readonly string $software,
        public readonly string $version,
        public readonly string $buildName,
        public readonly ?int $java,
        public readonly array $notes,
        public readonly bool $started,
        public readonly bool $attention = false,
    ) {}

    /** "Fabric 1.20.1" style label. */
    public function label(): string
    {
        return trim($this->software.' '.$this->version);
    }
}
