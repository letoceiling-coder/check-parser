<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DeployTriggerCommand extends Command
{
    protected $signature = 'deploy:trigger';
    protected $description = 'Only trigger deployment on server (no build, no git). Requires DEPLOY_URL and DEPLOY_TOKEN in .env';

    public function handle(): int
    {
        $deployUrl = env('DEPLOY_URL');
        $deployToken = env('DEPLOY_TOKEN');

        if (!$deployUrl || !$deployToken) {
            $this->error('DEPLOY_URL and DEPLOY_TOKEN must be set in .env');
            $this->line('Example: php artisan deploy:trigger');
            $this->line('Or manually: Invoke-WebRequest -Uri "$env:DEPLOY_URL/api/deploy" -Method POST -Headers @{Authorization="Bearer $env:DEPLOY_TOKEN"}');
            return Command::FAILURE;
        }

        $url = rtrim($deployUrl, '/') . '/api/deploy';
        $this->info('Triggering: ' . $url);

        try {
            $response = Http::withOptions(['verify' => false])
                ->withToken($deployToken)
                ->timeout(300)
                ->post($url);

            $this->line('Status: ' . $response->status());
            $body = $response->json();
            if (isset($body['log'])) {
                foreach ($body['log'] as $line) {
                    $this->line('  ' . $line);
                }
            } else {
                $this->line($response->body());
            }

            if ($response->successful()) {
                $this->info('Deployment completed successfully.');
                return Command::SUCCESS;
            }

            $this->error('Deployment failed.');
            if (!empty($body['errors'])) {
                foreach ($body['errors'] as $err) {
                    $this->error('  ' . $err);
                }
            }
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
