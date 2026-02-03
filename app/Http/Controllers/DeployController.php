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
        // Log that we reached the controller
        Log::info('DeployController::deploy called', [
            'method' => $request->method(),
            'path' => $request->path(),
            'url' => $request->url(),
            'full_url' => $request->fullUrl(),
        ]);
        
        // Verify token - try multiple methods
        $token = null;
        
        // Method 1: Bearer token
        $token = $request->bearerToken();
        
        // Method 2: Authorization header
        if (!$token && $request->hasHeader('Authorization')) {
            $authHeader = $request->header('Authorization');
            if (str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
            } else {
                $token = $authHeader;
            }
        }
        
        // Method 3: From request body or query
        if (!$token) {
            $token = $request->input('token') ?? $request->query('token');
        }
        
        // Read token directly from .env (bypass config cache)
        $expectedToken = env('DEPLOY_TOKEN');
        
        // Debug logging
        Log::info('Deploy attempt', [
            'token_received' => $token ? substr($token, 0, 10) . '...' : 'empty',
            'token_expected' => $expectedToken ? substr($expectedToken, 0, 10) . '...' : 'empty',
            'headers' => $request->headers->all(),
        ]);
        
        if (!$expectedToken) {
            return response()->json([
                'success' => false,
                'error' => 'DEPLOY_TOKEN not configured on server'
            ], 500);
        }
        
        if (!$token || $token !== $expectedToken) {
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
            
            // Fetch latest changes first
            $gitFetch = Process::path(base_path())->run('git fetch origin');
            if ($gitFetch->successful()) {
                $log[] = 'Fetched latest changes';
            }
            
            // Stash any local changes
            $gitStash = Process::path(base_path())->run('git stash');
            if ($gitStash->successful() && !empty(trim($gitStash->output()))) {
                $log[] = 'Stashed local changes';
            }
            
            // Reset to remote state (discard any remaining local changes)
            $gitReset = Process::path(base_path())->run('git reset --hard origin/main');
            if ($gitReset->successful()) {
                $log[] = 'Reset to remote state';
            } else {
                $errors[] = 'Git reset failed: ' . $gitReset->errorOutput();
            }
            
            // Clean untracked files (but keep .env, storage, vendor, node_modules)
            // Use git clean with exclusions
            // Clean untracked files (except important ones)
            // Note: git clean doesn't support multiple -e flags, so we use a different approach
            $gitClean = Process::path(base_path())->run('git clean -fd');
            if ($gitClean->successful()) {
                $cleanOutput = trim($gitClean->output());
                if (!empty($cleanOutput)) {
                    $log[] = 'Cleaned untracked files: ' . $cleanOutput;
                } else {
                    $log[] = 'No untracked files to clean';
                }
            }
            
            $log[] = 'Code updated successfully';

                   // Step 2: Ensure public/.htaccess and public/index.php exist
                   $publicHtaccess = public_path('.htaccess');
                   if (!file_exists($publicHtaccess)) {
                       $log[] = 'Creating public/.htaccess...';
                       $htaccessContent = <<<'EOF'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Serve static files directly (CSS, JS, images, fonts)
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteCond %{REQUEST_URI} \.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ico|json|map)$ [NC]
    RewriteRule ^ - [L]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
EOF;
                       file_put_contents($publicHtaccess, $htaccessContent);
                       $log[] = 'public/.htaccess created';
                   }
                   
                   $publicIndex = public_path('index.php');
                   if (!file_exists($publicIndex)) {
                       $log[] = 'Creating public/index.php...';
                       $indexContent = <<<'EOF'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
EOF;
                       file_put_contents($publicIndex, $indexContent);
                       $log[] = 'public/index.php created';
                   }

                   // Step 3: Ensure composer is installed in bin/composer and use it
                   $composerCmd = null;
                   
                   // First, check if bin/composer exists
                   $composerPath = base_path('bin/composer');
                   if (file_exists($composerPath)) {
                       // Test if it works
                       $testComposer = Process::path(base_path())->run("php {$composerPath} --version 2>&1");
                       if ($testComposer->successful()) {
                           $composerCmd = "php {$composerPath}";
                           $log[] = 'Using bin/composer: ' . trim($testComposer->output());
                       } else {
                           $log[] = 'bin/composer exists but failed to run, will reinstall';
                           // Remove broken composer
                           @unlink($composerPath);
                       }
                   }
                   
                   // If bin/composer doesn't exist or is broken, install it
                   if (!$composerCmd) {
                       $log[] = 'Installing composer to bin/composer...';
                       $installComposer = $this->installComposer($composerPath);
                       
                       if (!$installComposer['success']) {
                           $errorMsg = $installComposer['error'] ?: 'Unknown error during composer installation';
                           $errors[] = 'Failed to install composer: ' . $errorMsg;
                           $log[] = 'Composer installation failed: ' . $errorMsg;
                       } else {
                           // Verify installation
                           $testComposer = Process::path(base_path())->run("php {$composerPath} --version 2>&1");
                           if ($testComposer->successful()) {
                               $composerCmd = "php {$composerPath}";
                               $log[] = 'Composer installed successfully to bin/composer: ' . trim($testComposer->output());
                           } else {
                               $errors[] = 'Composer installed but failed to run: ' . trim($testComposer->output());
                               $log[] = 'Composer installation verification failed';
                           }
                       }
                   }

                   // Step 4: Install dependencies
                   if ($composerCmd) {
                       $log[] = 'Installing composer dependencies...';
                       $composerInstall = Process::path(base_path())->run("{$composerCmd} install --no-interaction --no-dev --optimize-autoloader");
                       
                       if (!$composerInstall->successful()) {
                           $errorOutput = trim($composerInstall->errorOutput());
                           $stdOutput = trim($composerInstall->output());
                           $errorMsg = $errorOutput ?: ($stdOutput ?: 'Unknown error');
                           $errors[] = 'Composer install failed: ' . $errorMsg;
                           $log[] = 'Composer install error: ' . $errorMsg;
                       } else {
                           $log[] = 'Dependencies installed successfully';
                       }
                   } else {
                       $errors[] = 'Composer is not available and installation failed';
                   }

                   // Step 5: Run migrations
            $log[] = 'Running migrations...';
            $migrate = Process::path(base_path())->run('php artisan migrate --force');
            
            if (!$migrate->successful()) {
                $errors[] = 'Migrations failed: ' . $migrate->errorOutput();
            } else {
                $log[] = 'Migrations completed successfully';
            }

                   // Step 6: Clear all caches
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
            $installerPath = sys_get_temp_dir() . '/composer-installer-' . uniqid() . '.php';
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'Laravel Deploy Script'
                ]
            ]);
            
            $installerContent = @file_get_contents($installerUrl, false, $context);
            if ($installerContent === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to download composer installer from ' . $installerUrl
                ];
            }
            
            if (file_put_contents($installerPath, $installerContent) === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to save composer installer to ' . $installerPath
                ];
            }

            // Set HOME to a writable directory to avoid permission issues
            $homeDir = getenv('HOME');
            if (empty($homeDir) || !is_writable($homeDir)) {
                $homeDir = sys_get_temp_dir();
            }
            
            // Run installer with explicit HOME directory
            $installResult = Process::path(base_path())
                ->env(['HOME' => $homeDir, 'COMPOSER_HOME' => $homeDir . '/.composer'])
                ->run("php {$installerPath} --install-dir={$binDir} --filename=composer 2>&1");
            
            if (!$installResult->successful()) {
                $errorOutput = trim($installResult->errorOutput());
                $stdOutput = trim($installResult->output());
                $errorMsg = $errorOutput ?: ($stdOutput ?: 'Unknown error during installation');
                return [
                    'success' => false,
                    'error' => 'Failed to install composer: ' . $errorMsg
                ];
            }
            
            // Verify that composer was actually created
            if (!file_exists($targetPath)) {
                return [
                    'success' => false,
                    'error' => 'Composer installer completed but composer file was not created at ' . $targetPath
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
