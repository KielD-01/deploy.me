<?php

namespace App\Deploy\Pipeline;

/**
 * A step a retry runs again even though the run it retries got past it:
 * what every run needs (the pre-flight checks, the lock), what the retry
 * sets up for itself (the release it picks up, this run's registry login),
 * and the env files, so a fix made to them since reaches the server.
 */
interface AlwaysRuns {}
