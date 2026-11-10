<?php

namespace App\Console\Commands;

use App\Domain\SampleData\SampleData;
use App\Domain\SampleData\SampleDataRunner;
use Illuminate\Console\Command;

class SampleDataCommand extends Command
{
    protected $signature = 'app:sample-data
        {--previous-year : Post the financial year that just closed, instead of the year that contains today}
        {--force : Load the sample business even when APP_ENV is production}';

    protected $description = 'Create or replace the Northwind Studio sample business for a full financial year.';

    public function handle(SampleDataRunner $runner): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->components->error('Refusing to load sample data while APP_ENV is production.');
            $this->line('Pass <fg=cyan>--force</> only on a demo instance. This replaces the '.SampleData::BUSINESS.' login and leaves every other business alone.');

            return self::FAILURE;
        }

        $previousYear = (bool) $this->option('previous-year');
        $this->components->info($previousYear
            ? 'Loading the closed financial year for '.SampleData::BUSINESS
            : 'Loading the current financial year for '.SampleData::BUSINESS);

        $run = $runner->run($previousYear);
        $year = $run->context->calendar;

        $this->newLine();
        $this->components->twoColumnDetail('Business', SampleData::BUSINESS);
        $this->components->twoColumnDetail('Sign in', SampleData::EMAIL.' / '.SampleData::PASSWORD);
        $this->components->twoColumnDetail('Financial year', $year->label().' ('.$year->start->toDateString().' – '.$year->end->toDateString().')');
        $this->components->twoColumnDetail('Posted through', $year->asOf->toDateString());
        foreach ($run->counts as $label => $count) {
            $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($label)), (string) $count);
        }
        $this->components->twoColumnDetail('Time', number_format($run->milliseconds).' ms');

        $this->newLine();
        $this->line('Chapter times:');
        foreach ($run->chapterMilliseconds as $chapter => $milliseconds) {
            $this->line(sprintf('  %-12s %6d ms', $chapter, $milliseconds));
        }

        $this->newLine();
        $this->components->info('Sample business is ready. Running this again replaces only that business.');

        return self::SUCCESS;
    }
}
