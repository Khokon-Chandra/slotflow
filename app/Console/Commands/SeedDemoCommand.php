<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * One command to get a runnable demo from a clean checkout.
 *
 * `migrate:fresh --seed` does the same thing, but this exists so the README
 * can say "run this" rather than "run these three, in this order, and clear
 * the cache afterwards or availability will look wrong".
 */
final class SeedDemoCommand extends Command
{
    protected $signature = 'demo:seed
                            {--fresh : Drop every table first}';

    protected $description = 'Seed the Bright Lane Studio demo workspace';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->components->task(
                'Rebuilding the schema',
                fn () => Artisan::call('migrate:fresh', ['--force' => true]) === 0,
            );
        }

        $this->components->task(
            'Seeding the demo workspace',
            function (): bool {
                $this->callSilently('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

                return true;
            },
        );

        // Availability is cached per tenant behind a version counter. A fresh
        // seed reuses the old ids, so without this the first page load can
        // show slots computed against data that no longer exists.
        $this->components->task('Clearing cached availability', function (): bool {
            Cache::flush();

            return true;
        });

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Demo ready</>', '');
        $this->components->twoColumnDetail('Booking page', '<fg=gray>/</>');
        $this->components->twoColumnDetail('Admin panel', '<fg=gray>/admin</>');
        $this->components->twoColumnDetail('API docs', '<fg=gray>/docs/api</>');
        $this->newLine();
        $this->components->twoColumnDetail('Owner', config('slotflow.demo.owner_email').' / '.config('slotflow.demo.password'));
        $this->components->twoColumnDetail('Staff', config('slotflow.demo.staff_email').' / '.config('slotflow.demo.password'));
        $this->components->twoColumnDetail('Customer', config('slotflow.demo.customer_email').' / '.config('slotflow.demo.password'));
        $this->newLine();

        return self::SUCCESS;
    }
}
