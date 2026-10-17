<?php

namespace App\Mcp;

/**
 * The stdio transport: one JSON-RPC message per line in, one answer per
 * line out, until the client closes stdin. Nothing else may reach the output
 * stream, so PHP's own warnings go to stderr.
 */
final class Stdio
{
    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public static function serve(Server $server, $input, $output): void
    {
        ini_set('display_errors', 'stderr');

        while (($line = fgets($input)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            $answer = $server->handleLine(trim($line));

            if ($answer !== null) {
                fwrite($output, $answer."\n");
                fflush($output);
            }
        }
    }
}
