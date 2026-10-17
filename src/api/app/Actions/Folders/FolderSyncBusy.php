<?php

namespace App\Actions\Folders;

use RuntimeException;

/**
 * The mapping already has a sync queued or running.
 */
final class FolderSyncBusy extends RuntimeException {}
