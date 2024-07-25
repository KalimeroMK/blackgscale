<?php

namespace App\Console\Commands;

use App\Jobs\DispatchRegistrationJob;
use Illuminate\Console\Command;

class DispatchRegistration extends Command
{
    protected $signature = 'register:dispatch';

    protected $description = 'Dispatch user registration jobs';

    public function handle()
    {
        DispatchRegistrationJob::dispatch();
        $this->info('Dispatched registration job for user');
    }
}