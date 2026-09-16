<?php

// Runs the real `artisan queue:work` with Carbon's clock running at
// VIRTUAL_SPEED x from VIRTUAL_START. Everything the job and the Redis queue
// use for time (now(), delay due-times, delayed-job migration) goes through
// Carbon, so the whole worker sees one consistent accelerated clock.

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$base = '/Users/wasiq/Desktop/development/code/ReviewEngine/backend';
chdir($base);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';

$virtualStart = CarbonImmutable::parse(getenv('VIRTUAL_START'))->utc();
$speed = (float) getenv('VIRTUAL_SPEED');
$realStart = microtime(true);

Carbon::setTestNow(function () use ($virtualStart, $speed, $realStart) {
    $elapsed = (microtime(true) - $realStart) * $speed;

    return Carbon::instance($virtualStart->addMicroseconds((int) round($elapsed * 1_000_000)));
});

fwrite(STDERR, sprintf("virtual clock: start %s (NY %s), speed %sx\n",
    $virtualStart->toIso8601String(), $virtualStart->setTimezone('America/New_York')->format('D H:i'), $speed));

$kernel = $app->make(Kernel::class);
$status = $kernel->handle(new ArgvInput(array_merge(['artisan'], array_slice($argv, 1))), new ConsoleOutput);
$kernel->terminate(null, $status);
exit($status);
