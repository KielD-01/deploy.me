<?php

namespace App\Mcp;

use RuntimeException;

/**
 * A tool call that can't go ahead: the agent gets the message back as the
 * tool's result (isError), so it can correct the call and try again.
 */
class ToolFailed extends RuntimeException {}
