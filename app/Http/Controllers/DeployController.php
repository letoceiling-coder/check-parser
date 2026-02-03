<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class DeployController extends Controller
{
    /**
     * Handle deployment request
     */
    public function deploy(Request $request): JsonResponse
    {
        // Verify token
        $token = $request->bearerToken() ?? $request->header('Authorization');
        
        // Remove 'Bearer ' prefix if present
        if ($token && str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }
        
        $expectedToken = env('DEPLOY_TOKEN');
        
        if (!$expectedToken || $token !== $expectedToken) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized: Invalid token'
            ], 401);
        }

        $log = [];
        $errors = [];

        try {
            // Step 1: Update code from git
            $log[] = 'Updating code from git...';
            $gitPull = Process::path(base_path())->run('git pull');
            
            if (!$gitPull->successful()) {
                $errors[] = 'Git pull failed: ' . $gitPull->errorOutput();
            } else {
                $log[] = 'Code updated successfully: ' . $gitPull->output();
            }

            // Step 2: Ensure composer is installed in bin/composer
            $composerPath = base_path('bin/composer');
            if (!file_exists($composerPath)) {
                $log[] = 'Installing composer to bin/composer...';
                $installComposer = $this->installComposer($composerPath);
                
                if (!$installComposer['success']) {
                    $errors[] = $installComposer['error'];
                } else {
                    $log[] = 'Composer installed successfully';
                }
            }

            // Step 3: Install dependencies
            $log[] = 'Installing composer dependencies...';
            $composerPath = file_exists(base_path('bin/composer')) 
                ? 'bin/composer' 
                : 'composer';
            $composerInstall = Process::path(base_path())->run("php {$composerPath} install --no-interaction --no-dev --optimize-autoloader");
            
            if (!$composerInstall->successful()) {
                $errors[] = 'Composer install failed: ' . $composerInstall->errorOutput();
            } else {
                $log[] = 'Dependencies installed successfully';
            }

            // Step 4: Run migrations
            $log[] = 'Running migrations...';
            $migrate = Process::path(base_path())->run('php artisan migrate --force');
            
            if (!$migrate->successful()) {
                $errors[] = 'Migrations failed: ' . $migrate->errorOutput();
            } else {
                $log[] = 'Migrations completed successfully';
            }

            // Step 5: Clear all caches
            $log[] = 'Clearing caches...';
            $cacheCommands = [
                'config:clear',
                'cache:clear',
                'route:clear',
                'view:clear',
                'event:clear',
            ];

            foreach ($cacheCommands as $command) {
                $result = Process::path(base_path())->run("php artisan {$command}");
                if ($result->successful()) {
                    $log[] = "Cache cleared: {$command}";
                } else {
                    $errors[] = "Failed to clear cache {$command}: " . $result->errorOutput();
                }
            }

            // Optimize
            $log[] = 'Optimizing application...';
            $optimize = Process::path(base_path())->run('php artisan optimize');
            if ($optimize->successful()) {
                $log[] = 'Application optimized';
            } else {
                $errors[] = 'Optimization failed: ' . $optimize->errorOutput();
            }

            // Clear opcache if available
            if (function_exists('opcache_reset')) {
                opcache_reset();
                $log[] = 'OPcache cleared';
            }

            if (count($errors) > 0) {
                return response()->json([
                    'success' => false,
                    'errors' => $errors,
                    'log' => $log
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Deployment completed successfully',
                'log' => $log
            ]);

        } catch (\Exception $e) {
            Log::error('Deployment error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Deployment failed: ' . $e->getMessage(),
                'log' => $log,
                'errors' => $errors
            ], 500);
        }
    }

    /**
     * Install composer to bin/composer
     */
    private function installComposer(string $targetPath): array
    {
        try {
            // Create bin directory if it doesn't exist
            $binDir = dirname($targetPath);
            if (!is_dir($binDir)) {
                mkdir($binDir, 0755, true);
            }

            // Download composer installer
            $installerUrl = 'https://getcomposer.org/installer';
            $installerPath = sys_get_temp_dir() . '/composer-installer.php';
            
            $installerContent = file_get_contents($installerUrl);
            if ($installerContent === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to download composer installer'
                ];
            }
            
            file_put_contents($installerPath, $installerContent);

            // Run installer
            $installResult = Process::path(base_path())->run("php {$installerPath} --install-dir={$binDir} --filename=composer");
            
            if (!$installResult->successful()) {
                return [
                    'success' => false,
                    'error' => 'Failed to install composer: ' . $installResult->errorOutput()
                ];
            }

            // Clean up installer
            if (file_exists($installerPath)) {
                unlink($installerPath);
            }

            return ['success' => true];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception during composer installation: ' . $e->getMessage()
            ];
        }
    }
}
