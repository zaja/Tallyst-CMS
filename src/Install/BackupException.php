<?php

namespace App\Install;

/**
 * Thrown when a DB backup cannot be produced (no dump binary, or mysqldump failed). The caller
 * (the upgrade-finalize command) decides whether that aborts the upgrade or, with an explicit
 * --no-backup / operator confirmation, continues — the service NEVER silently pretends a backup
 * succeeded.
 */
final class BackupException extends \RuntimeException
{
}
