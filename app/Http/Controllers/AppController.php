<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class AppController extends Controller
{
    /**
     * Serve React application
     */
    public function index()
    {
        $indexPath = public_path('index.html');
        
        if (!file_exists($indexPath)) {
            abort(404, 'React application not found. Please run "npm run build" in frontend directory.');
        }
        
        return response(File::get($indexPath))->header('Content-Type', 'text/html');
    }
    
    /**
     * Serve static assets from React build
     * Note: Static files are now in public/static and can be served directly by web server
     * This method is kept for API route compatibility
     */
    public function static($path)
    {
        // Decode URL-encoded path
        $path = urldecode($path);
        
        // Security: prevent directory traversal
        $path = str_replace('..', '', $path);
        $path = ltrim($path, '/');
        
        $basePath = public_path('static');
        $filePath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        
        // Additional security check
        $realBasePath = realpath($basePath);
        $realFilePath = realpath($filePath);
        
        if (!$realBasePath) {
            abort(404, 'Static directory not found');
        }
        
        if (!$realFilePath) {
            abort(404, 'File not found: ' . $path);
        }
        
        // Ensure file is within base directory
        if (strpos($realFilePath, $realBasePath) !== 0) {
            abort(404, 'Invalid file path');
        }
        
        if (!is_file($realFilePath)) {
            abort(404, 'Not a file');
        }
        
        $mimeType = $this->getMimeType($realFilePath);
        
        return response(File::get($realFilePath))
            ->header('Content-Type', $mimeType)
            ->header('Cache-Control', 'public, max-age=31536000');
    }
    
    private function getMimeType($filePath)
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        $mimeTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
