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
    protected $signature = 'deploy 
        {--no-build : Skip local frontend build (when using SSH, build runs on server)}
        {--no-ssh : Use webhook only, do not run update via SSH even if DEPLOY_SSH is set}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Commit, push, then update server (via SSH: pull, composer, migrate, frontend build, cache) or webhook';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting deployment process...');

        $sshHost = config('deploy.ssh_host') ?: env('DEPLOY_SSH');
        $sshPath = config('deploy.ssh_path') ?: env('DEPLOY_SSH_PATH');
        $useSsh = !$this->option('no-ssh') && $sshHost && $sshPath;

        // Step 1: Local build only if NOT using SSH (on SSH, build runs on server)
        if (!$useSsh && !$this->option('no-build')) {
            $this->info('Building React application locally...');
            $buildResult = Process::path(base_path('frontend'))->timeout(300)->run('npm run build');
            if (!$buildResult->successful()) {
                $this->error('Failed to build assets: ' . $buildResult->errorOutput());
                return Command::FAILURE;
            }
            $this->info('Assets built successfully.');
        } elseif ($useSsh) {
            $this->info('Frontend will be built on server (SSH).');
        } else {
            $this->warn('Skipping local build (--no-build).');
        }

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

        // Step 5: Update on server
        if ($useSsh) {
            return $this->runDeployViaSsh($sshHost, $sshPath);
        }

        return $this->runDeployViaWebhook();
    }

    /**
     * Run update on server via SSH: git pull, composer, migrate, frontend build, cache clear.
     */
    private function runDeployViaSsh(string $sshHost, string $sshPath): int
    {
        $this->info('Running update on server via SSH (' . $sshHost . ')...');
        $sshPath = rtrim(str_replace('\\', '/', $sshPath), '/');
        $remoteCmd = "cd " . $sshPath . " && git pull origin main && bash update-on-server.sh";
        $fullCmd = "ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 -o ServerAliveInterval=15 " . $sshHost . " " . escapeshellarg($remoteCmd);
        $result = Process::timeout(600)->run($fullCmd, function (string $type, string $output): void {
            $this->getOutput()->write($output);
        });
        if (!$result->successful()) {
            $this->error('SSH deploy failed.');
            $this->line($result->output());
            $this->error($result->errorOutput());
            return Command::FAILURE;
        }
        $this->info('Deployment on server completed successfully.');
        return Command::SUCCESS;
    }

    /**
     * Trigger deployment via webhook (POST /api/deploy).
     */
    private function runDeployViaWebhook(): int
    {
        $deployUrl = env('DEPLOY_URL');
        $deployToken = env('DEPLOY_TOKEN');
        if (!$deployUrl || !$deployToken) {
            $this->error('DEPLOY_URL and DEPLOY_TOKEN must be set in .env when not using SSH deploy.');
            return Command::FAILURE;
        }
        $this->info('Triggering deployment on server (webhook)...');
        $url = rtrim($deployUrl, '/') . '/api/deploy';
        try {
            $response = Http::withOptions(['verify' => false])
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $deployToken,
                    'Accept' => 'application/json',
                ])->post($url);
            if ($response->successful()) {
                $this->info('Deployment triggered successfully!');
                $this->line('Response: ' . $response->body());
                return Command::SUCCESS;
            }
            $this->error('Deployment request failed: ' . $response->status());
            $this->error('Response: ' . $response->body());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error triggering deployment: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
