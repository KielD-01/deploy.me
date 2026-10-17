<?php

namespace App\Support;

use DateTimeZone;

/**
 * The timezone pages print times in: the browser's own, which the app layout reports in
 * the `tz` cookie, so a run started at 22:16 UTC reads 01:16 in Kyiv. Only what a page
 * prints moves; stored times stay UTC. Until the browser has said, and wherever there is
 * no browser (a queued job, a Telegram alert), it is the app's timezone.
 *
 * Pages use it through Carbon: `$run->created_at->forViewer()->format('H:i')`
 * (AppServiceProvider).
 */
final class ViewerTimezone
{
    public const COOKIE = 'tz';

    public static function name(): string
    {
        $zone = request()->cookie(self::COOKIE);

        // Only a zone PHP knows, old spellings like Europe/Kiev included: the cookie holds
        // whatever the browser, or anyone else, put in it.
        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            ? $zone
            : (string) config('app.timezone');
    }
}
