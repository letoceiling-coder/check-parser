<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class DeployCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build project, commit to git and trigger deployment on server';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting deployment process...');

        // Step 1: Build React app locally
        $this->info('Building React application...');
        $buildResult = Process::path(base_path('frontend'))->timeout(300)->run('npm run build');
        
        if (!$buildResult->successful()) {
            $this->error('Failed to build assets: ' . $buildResult->errorOutput());
            return Command::FAILURE;
        }
        $this->info('Assets built successfully.');

        // Step 2: Add all changes to git
        $this->info('Adding changes to git...');
        $addResult = Process::run('git add .');
        
        if (!$addResult->successful()) {
            $this->error('Failed to add files to git: ' . $addResult->errorOutput());
            return Command::FAILURE;
        }

        // Step 3: Check if there are changes to commit
        $statusResult = Process::run('git status --porcelain');
        $hasChanges = !empty(trim($statusResult->output()));
        
        if ($hasChanges) {
            // Commit changes
            $this->info('Committing changes...');
            $commitMessage = 'Deploy: ' . now()->format('Y-m-d H:i:s');
            $commitResult = Process::run("git commit -m \"{$commitMessage}\"");
            
            if (!$commitResult->successful()) {
                $this->error('Failed to commit changes: ' . $commitResult->errorOutput());
                return Command::FAILURE;
            }
            $this->info('Changes committed successfully.');
        } else {
            $this->info('No changes to commit.');
        }

        // Step 4: Push to git
        $this->info('Pushing to git...');
        $pushResult = Process::run('git push');
        
        if (!$pushResult->successful()) {
            $this->error('Failed to push to git: ' . $pushResult->errorOutput());
            return Command::FAILURE;
        }
        $this->info('Changes pushed to git successfully.');

        // Step 5: Trigger deployment on server
        $deployUrl = env('DEPLOY_URL');
        $deployToken = env('DEPLOY_TOKEN');

        if (!$deployUrl || !$deployToken) {
            $this->error('DEPLOY_URL and DEPLOY_TOKEN must be set in .env file');
            return Command::FAILURE;
        }

        $this->info('Triggering deployment on server...');
        
        try {
            $response = Http::withOptions([
                'verify' => false, // Отключить проверку SSL сертификата
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $deployToken,
                'Accept' => 'application/json',
            ])->post($deployUrl . '/api/deploy');

            if ($response->successful()) {
                $this->info('Deployment triggered successfully!');
                $this->line('Response: ' . $response->body());
                return Command::SUCCESS;
            } else {
                $this->error('Deployment request failed: ' . $response->status());
                $this->error('Response: ' . $response->body());
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('Error triggering deployment: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
