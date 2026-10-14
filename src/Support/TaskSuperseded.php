<?php

declare(strict_types=1);

namespace McToolkit\Support;

/**
 * Thrown from a job's progress callback once its {@see ServerTask} entry no longer belongs
 * to it (a dead-looking run was dismissed and another install took the server over).
 *
 * Installers rethrow it untouched: no staging clean-up and no restart, because both would
 * now act on files and power state the newer run owns.
 */
final class TaskSuperseded extends \RuntimeException {}
