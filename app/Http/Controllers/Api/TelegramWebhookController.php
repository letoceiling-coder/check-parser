<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramWebhookController extends Controller
{
    /**
     * Handle Telegram webhook
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $update = $request->all();
            Log::info('Telegram webhook received', ['update' => $update]);

            // Find bot by token (we need to identify which bot this update is for)
            // Telegram sends updates to webhook URL, we need to identify bot
            // For now, we'll get bot token from webhook URL or use first active bot
            // In production, you might want to use secret_token or bot_id in URL
            
            $bot = $this->findBotByUpdate($update);
            if (!$bot) {
                Log::warning('Bot not found for update', ['update' => $update]);
                return response()->json(['ok' => true]); // Return ok to Telegram
            }

            // Handle message
            if (isset($update['message'])) {
                $this->handleMessage($bot, $update['message']);
            }

            // Handle callback query (button clicks)
            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($bot, $update['callback_query']);
            }

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Telegram webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['ok' => true]); // Always return ok to Telegram
        }
    }

    /**
     * Find bot by update
     * Try to identify bot by checking all active bots and matching token
     */
    private function findBotByUpdate(array $update): ?TelegramBot
    {
        // Get all active bots
        $bots = TelegramBot::where('is_active', true)->get();
        
        // If only one bot, return it
        if ($bots->count() === 1) {
            return $bots->first();
        }
        
        // If multiple bots, we need to identify by bot_id or token
        // For now, try to get bot info from message and match
        // In production, you might want to use bot_id in webhook URL path
        
        // Try to get bot info from callback_query or message
        $botId = null;
        if (isset($update['callback_query']['from']['id'])) {
            // This is not reliable, but we'll use first active bot
        }
        
        // For now, return first active bot
        // TODO: Implement proper bot identification (e.g., via webhook URL path)
        return $bots->first();
    }

    /**
     * Handle incoming message
     */
    private function handleMessage(TelegramBot $bot, array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? null;
        $photo = $message['photo'] ?? null;
        $document = $message['document'] ?? null;

        // Handle /start command
        if ($text && str_starts_with($text, '/start')) {
            $this->handleStartCommand($bot, $chatId);
            return;
        }

        // Handle photo (check image)
        if ($photo) {
            $this->handlePhoto($bot, $chatId, $photo);
            return;
        }

        // Handle document (check image file or PDF)
        if ($document && ($this->isImageDocument($document) || $this->isPdfDocument($document))) {
            $this->handleDocument($bot, $chatId, $document);
            return;
        }

        // Handle other messages
        if ($text) {
            $this->sendMessage($bot, $chatId, 'Пожалуйста, отправьте фото чека для обработки.');
        }
    }

    /**
     * Handle /start command
     */
    private function handleStartCommand(TelegramBot $bot, int $chatId): void
    {
        $welcomeMessage = "👋 Привет! Я бот для обработки чеков.\n\n";
        $welcomeMessage .= "📸 Отправьте мне фото чека или PDF документ, и я извлеку сумму платежа.\n\n";
        $welcomeMessage .= "Просто отправьте фото или PDF чека, и я обработаю его!";

        $this->sendMessage($bot, $chatId, $welcomeMessage);
    }

    /**
     * Handle photo
     */
    private function handlePhoto(TelegramBot $bot, int $chatId, array $photo): void
    {
        // Send "processing" message
        $this->sendMessage($bot, $chatId, '⏳ Обрабатываю чек...');

        Log::info('Processing photo', [
            'chat_id' => $chatId,
            'photo_sizes' => count($photo),
            'sizes' => array_map(fn($p) => ['width' => $p['width'] ?? 0, 'height' => $p['height'] ?? 0, 'file_size' => $p['file_size'] ?? 0], $photo)
        ]);

        try {
            // Try all photo sizes, starting from largest (best quality)
            // Telegram sends multiple sizes, try them all
            $photoSizes = array_reverse($photo); // Start with largest
            
            $checkData = null;
            $processedFiles = [];

            foreach ($photoSizes as $index => $photoSize) {
                $fileId = $photoSize['file_id'];
                $width = $photoSize['width'] ?? 0;
                $height = $photoSize['height'] ?? 0;
                
                Log::info("Trying photo size {$index}", [
                    'file_id' => substr($fileId, 0, 20) . '...',
                    'width' => $width,
                    'height' => $height
                ]);
                
                // Get file from Telegram
                $file = $this->getFile($bot, $fileId);
                if (!$file) {
                    Log::warning("Failed to get file for photo size {$index}");
                    continue;
                }

                // Download file
                $filePath = $this->downloadFile($bot, $file['file_path']);
                if (!$filePath) {
                    Log::warning("Failed to download file for photo size {$index}");
                    continue;
                }

                Log::info("Downloaded file", ['path' => $filePath, 'size' => $file['file_size'] ?? 0]);
                $processedFiles[] = $filePath;

                // Process check using OCR
                Log::info("Starting OCR processing", ['file' => $filePath]);
                $checkData = $this->processCheckWithOCR($filePath, false);
                
                if ($checkData) {
                    Log::info("Check data successfully extracted!", ['check_data' => $checkData]);
                    // Success! Clean up and return
                    foreach ($processedFiles as $pf) {
                        Storage::disk('local')->delete($pf);
                    }
                    $this->sendCheckResult($bot, $chatId, $checkData);
                    return;
                } else {
                    Log::warning("OCR extraction failed for photo size {$index}");
                }
            }

            // If we get here, all attempts failed
            Log::error("All OCR extraction attempts failed", [
                'photo_sizes_tried' => count($photoSizes),
                'files_processed' => count($processedFiles),
                'chat_id' => $chatId
            ]);
            
            foreach ($processedFiles as $pf) {
                Storage::disk('local')->delete($pf);
            }
            
            $this->sendMessage($bot, $chatId, '❌ Не удалось распознать текст на чеке. Убедитесь, что фото четкое и текст хорошо виден. Попробуйте отправить другое фото или PDF.');
        } catch (\Exception $e) {
            Log::error('Error processing photo: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            $this->sendMessage($bot, $chatId, '❌ Произошла ошибка при обработке чека.');
        }
    }

    /**
     * Handle document (image file)
     */
    private function handleDocument(TelegramBot $bot, int $chatId, array $document): void
    {
        $fileId = $document['file_id'];

        // Send "processing" message
        $this->sendMessage($bot, $chatId, '⏳ Обрабатываю чек...');

        try {
            // Get file from Telegram
            $file = $this->getFile($bot, $fileId);
            if (!$file) {
                $this->sendMessage($bot, $chatId, '❌ Ошибка при получении файла.');
                return;
            }

            // Download file
            $filePath = $this->downloadFile($bot, $file['file_path']);
            if (!$filePath) {
                $this->sendMessage($bot, $chatId, '❌ Ошибка при загрузке файла.');
                return;
            }

            // Process check using OCR
            $isPdf = $this->isPdfDocument($document);
            Log::info("Processing document with OCR", ['is_pdf' => $isPdf, 'file' => $filePath]);
            $checkData = $this->processCheckWithOCR($filePath, $isPdf);

            // Send result
            if ($checkData) {
                $this->sendCheckResult($bot, $chatId, $checkData);
            } else {
                $this->sendMessage($bot, $chatId, '❌ Не удалось распознать текст на чеке. Убедитесь, что фото четкое и текст хорошо виден. Попробуйте отправить другое фото или PDF.');
            }

            // Clean up
            Storage::disk('local')->delete($filePath);
        } catch (\Exception $e) {
            Log::error('Error processing document: ' . $e->getMessage());
            $this->sendMessage($bot, $chatId, '❌ Произошла ошибка при обработке чека.');
        }
    }

    /**
     * Check if document is an image or PDF
     */
    private function isImageDocument(array $document): bool
    {
        $mimeType = $document['mime_type'] ?? '';
        return str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';
    }

    /**
     * Check if document is PDF
     */
    private function isPdfDocument(array $document): bool
    {
        $mimeType = $document['mime_type'] ?? '';
        $fileName = $document['file_name'] ?? '';
        return $mimeType === 'application/pdf' || str_ends_with(strtolower($fileName), '.pdf');
    }

    /**
     * Get file info from Telegram
     */
    private function getFile(TelegramBot $bot, string $fileId): ?array
    {
        try {
            $response = Http::get("https://api.telegram.org/bot{$bot->token}/getFile", [
                'file_id' => $fileId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['result'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting file: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Download file from Telegram
     */
    private function downloadFile(TelegramBot $bot, string $filePath): ?string
    {
        try {
            $url = "https://api.telegram.org/file/bot{$bot->token}/{$filePath}";
            $contents = Http::get($url)->body();

            $localPath = 'telegram/' . basename($filePath);
            Storage::disk('local')->put($localPath, $contents);

            return $localPath;
        } catch (\Exception $e) {
            Log::error('Error downloading file: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Process check using OCR - extract text and parse payment amount
     * Tries multiple OCR methods
     */
    private function processCheckWithOCR(string $filePath, bool $isPdf = false): ?array
    {
        try {
            $fullPath = Storage::disk('local')->path($filePath);

            // Convert PDF to image if needed
            if ($isPdf) {
                $fullPath = $this->convertPdfToImage($fullPath);
                if (!$fullPath) {
                    Log::error('Failed to convert PDF to image');
                    return null;
                }
            }

            // Try multiple OCR methods
            $ocrMethods = [
                'extractTextWithYandexVision',
                'extractTextWithOCRspace',
                'extractTextWithTesseract',
                'extractTextWithGoogleVision',
            ];

            $extractedText = null;
            foreach ($ocrMethods as $method) {
                try {
                    Log::info("Trying OCR method: {$method}", ['file' => $fullPath]);
                    $text = $this->$method($fullPath);
                    if ($text && !empty(trim($text))) {
                        Log::info("Text extracted using {$method}", [
                            'text_length' => strlen($text),
                            'text_preview' => substr($text, 0, 300)
                        ]);
                        $extractedText = $text;
                        break;
                    } else {
                        Log::debug("OCR method {$method} returned empty text");
                    }
                } catch (\Exception $e) {
                    Log::warning("OCR method {$method} failed: " . $e->getMessage(), [
                        'trace' => substr($e->getTraceAsString(), 0, 500)
                    ]);
                    continue;
                }
            }

            if (!$extractedText) {
                Log::error('All OCR methods failed', [
                    'file' => $fullPath,
                    'file_exists' => file_exists($fullPath),
                    'file_size' => file_exists($fullPath) ? filesize($fullPath) : 0
                ]);
                return null;
            }

            // Parse payment amount from text
            $checkData = $this->parsePaymentAmount($extractedText);
            
            if ($checkData) {
                Log::info('Payment amount parsed successfully', ['check_data' => $checkData]);
                return $checkData;
            }

            Log::warning('Failed to parse payment amount from extracted text');
            return null;
        } catch (\Exception $e) {
            Log::error('Error processing check with OCR: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Process check - extract QR code and parse data (legacy method, kept for compatibility)
     * Tries multiple methods and image preprocessing variations
     */
    private function processCheck(string $filePath): ?array
    {
        try {
            $fullPath = Storage::disk('local')->path($filePath);

            // Try original image first (without preprocessing)
            Log::info("Attempting QR recognition on original image", ['file' => $filePath]);
            $result = $this->tryExtractQRCode($fullPath);
            if ($result) {
                Log::info("QR code successfully recognized from original image");
                return $result;
            }

            // Try with different preprocessing variations
            $preprocessVariations = [
                ['contrast' => 1, 'sharpen' => true, 'grayscale' => true],
                ['contrast' => 2, 'sharpen' => true, 'grayscale' => true],
                ['contrast' => 3, 'sharpen' => true, 'grayscale' => true],
                ['contrast' => 1, 'sharpen' => false, 'grayscale' => true],
                ['contrast' => 1, 'sharpen' => true, 'grayscale' => false],
                ['contrast' => 2, 'sharpen' => false, 'grayscale' => true],
                ['contrast' => 1, 'sharpen' => false, 'grayscale' => false],
                ['contrast' => 0, 'sharpen' => true, 'grayscale' => true], // Only sharpen and grayscale
            ];

            foreach ($preprocessVariations as $variationIndex => $variation) {
                Log::info("Trying preprocessing variation {$variationIndex}", $variation);
                $processedPath = $this->preprocessImageWithOptions($fullPath, $variation);
                if ($processedPath) {
                    Log::info("Processed image saved", ['processed' => $processedPath]);
                    $result = $this->tryExtractQRCode(Storage::disk('local')->path($processedPath));
                    if ($result) {
                        Log::info("QR code successfully recognized from preprocessed image (variation {$variationIndex})");
                        Storage::disk('local')->delete($processedPath);
                        return $result;
                    }
                    Storage::disk('local')->delete($processedPath);
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error processing check: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Try to extract QR code using all available methods
     */
    private function tryExtractQRCode(string $filePath): ?array
    {
        // Try multiple methods in order of reliability
        $methods = [
            'extractQRCodeWithAPI1',      // qrserver.com (most reliable)
            'extractQRCodeWithAPI6',      // qr-server.com (alternative)
            'extractQRCodeWithAPI7',      // qr-code-reader.com
            'extractQRCodeWithAPI8',      // qrcode.tec-it.com
            'extractQRCodeWithAPI3',      // api.qrserver alternative method
            'extractQRCodeWithAPI2',      // goqr.me (may have DNS issues)
            'extractQRCodeWithAPI4',      // api4free.com (may have DNS issues)
            'extractQRCodeWithAPI5',      // qr-code-reader.p.rapidapi.com (if key available)
            'extractQRCodeWithZxing',     // zxing (if available)
            'extractQRCodeWithPython',    // Python pyzbar (if available)
        ];

        foreach ($methods as $method) {
            try {
                Log::debug("Trying method: {$method}");
                $qrData = $this->$method($filePath);
                if ($qrData && !empty(trim($qrData))) {
                    Log::info("QR code extracted using {$method}", [
                        'data_length' => strlen($qrData),
                        'data_preview' => substr($qrData, 0, 100)
                    ]);
                    $parsed = $this->parseCheckData($qrData);
                    if ($parsed) {
                        Log::info("Check data parsed successfully", ['check_data' => $parsed]);
                        return $parsed;
                    } else {
                        Log::warning("QR data extracted but parsing failed", ['qr_data' => substr($qrData, 0, 200)]);
                    }
                }
            } catch (\Exception $e) {
                Log::debug("Method {$method} failed: " . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Preprocess image with specific options
     */
    private function preprocessImageWithOptions(string $sourcePath, array $options): ?string
    {
        try {
            if (!extension_loaded('gd') && !extension_loaded('imagick')) {
                return null;
            }

            $imageInfo = getimagesize($sourcePath);
            if (!$imageInfo) {
                return null;
            }

            $mimeType = $imageInfo['mime'];
            $processedPath = 'telegram/processed_' . uniqid() . '.jpg';

            if (extension_loaded('imagick')) {
                return $this->preprocessWithImagickOptions($sourcePath, $processedPath, $options);
            } elseif (extension_loaded('gd')) {
                return $this->preprocessWithGDOptions($sourcePath, $processedPath, $mimeType, $options);
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Image preprocessing with options failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image with Imagick using specific options
     */
    private function preprocessWithImagickOptions(string $sourcePath, string $targetPath, array $options): ?string
    {
        try {
            $image = new \Imagick($sourcePath);
            
            if ($options['grayscale'] ?? true) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            }
            
            // Normalize
            $image->normalizeImage();
            
            // Contrast
            $contrast = $options['contrast'] ?? 1;
            for ($i = 0; $i < $contrast; $i++) {
                $image->contrastImage(1);
            }
            
            // Sharpen
            if ($options['sharpen'] ?? true) {
                $image->sharpenImage(0, 1);
            }
            
            // Save
            $image->setImageFormat('jpg');
            $image->setImageCompressionQuality(95);
            $image->writeImage(Storage::disk('local')->path($targetPath));
            $image->destroy();

            return $targetPath;
        } catch (\Exception $e) {
            Log::debug('Imagick preprocessing with options failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image with GD using specific options
     */
    private function preprocessWithGDOptions(string $sourcePath, string $targetPath, string $mimeType, array $options): ?string
    {
        try {
            // Load image
            switch ($mimeType) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($sourcePath);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($sourcePath);
                    break;
                default:
                    return null;
            }

            if (!$image) {
                return null;
            }

            // Grayscale
            if ($options['grayscale'] ?? true) {
                imagefilter($image, IMG_FILTER_GRAYSCALE);
            }
            
            // Contrast
            $contrast = $options['contrast'] ?? 1;
            for ($i = 0; $i < $contrast; $i++) {
                imagefilter($image, IMG_FILTER_CONTRAST, -20);
            }
            
            // Sharpen
            if ($options['sharpen'] ?? true) {
                $sharpen = [
                    [-1, -1, -1],
                    [-1, 16, -1],
                    [-1, -1, -1]
                ];
                imageconvolution($image, $sharpen, 8, 0);
            }

            // Save
            $targetFullPath = Storage::disk('local')->path($targetPath);
            imagejpeg($image, $targetFullPath, 95);
            imagedestroy($image);

            return $targetPath;
        } catch (\Exception $e) {
            Log::debug('GD preprocessing with options failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image to improve QR code recognition
     */
    private function preprocessImage(string $filePath): ?string
    {
        try {
            if (!extension_loaded('gd') && !extension_loaded('imagick')) {
                return null; // No image processing available
            }

            $fullPath = Storage::disk('local')->path($filePath);
            $imageInfo = getimagesize($fullPath);
            
            if (!$imageInfo) {
                return null;
            }

            $mimeType = $imageInfo['mime'];
            $processedPath = 'telegram/processed_' . uniqid() . '.jpg';

            if (extension_loaded('imagick')) {
                return $this->preprocessWithImagick($fullPath, $processedPath);
            } elseif (extension_loaded('gd')) {
                return $this->preprocessWithGD($fullPath, $processedPath, $mimeType);
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Image preprocessing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image with Imagick
     */
    private function preprocessWithImagick(string $sourcePath, string $targetPath): ?string
    {
        try {
            $image = new \Imagick($sourcePath);
            
            // Enhance contrast
            $image->normalizeImage();
            
            // Sharpen
            $image->sharpenImage(0, 1);
            
            // Convert to grayscale for better QR recognition
            $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            
            // Increase contrast
            $image->contrastImage(1);
            
            // Save
            $image->setImageFormat('jpg');
            $image->setImageCompressionQuality(95);
            $image->writeImage(Storage::disk('local')->path($targetPath));
            $image->destroy();

            return $targetPath;
        } catch (\Exception $e) {
            Log::debug('Imagick preprocessing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image with GD
     */
    private function preprocessWithGD(string $sourcePath, string $targetPath, string $mimeType): ?string
    {
        try {
            // Load image based on type
            switch ($mimeType) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($sourcePath);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($sourcePath);
                    break;
                default:
                    return null;
            }

            if (!$image) {
                return null;
            }

            // Convert to grayscale
            imagefilter($image, IMG_FILTER_GRAYSCALE);
            
            // Enhance contrast
            imagefilter($image, IMG_FILTER_CONTRAST, -20);
            
            // Sharpen
            $sharpen = [
                [-1, -1, -1],
                [-1, 16, -1],
                [-1, -1, -1]
            ];
            imageconvolution($image, $sharpen, 8, 0);

            // Save
            $targetFullPath = Storage::disk('local')->path($targetPath);
            imagejpeg($image, $targetFullPath, 95);
            imagedestroy($image);

            return $targetPath;
        } catch (\Exception $e) {
            Log::debug('GD preprocessing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using zxing (requires Java and zxing installed)
     */
    private function extractQRCodeWithZxing(string $filePath): ?string
    {
        try {
            // Check if zxing is available
            $zxingPath = exec('which zxing 2>/dev/null') ?: exec('which java 2>/dev/null');
            if (!$zxingPath) {
                return null;
            }

            // Try to decode QR code
            $command = "zxing --decode {$filePath} 2>/dev/null";
            $output = exec($command, $outputArray, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                return $output;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract QR code using PHP library (placeholder - implement if library is installed)
     */
    private function extractQRCodeWithLibrary(string $filePath): ?string
    {
        // TODO: Implement if QR code library is installed
        // Example: using simple-qrcode or other library
        return null;
    }

    /**
     * Extract QR code using API 1 (qrserver.com)
     */
    private function extractQRCodeWithAPI1(string $filePath): ?string
    {
        try {
            $url = 'https://api.qrserver.com/v1/read-qr-code/';
            $fileContents = file_get_contents($filePath);
            $fileSize = strlen($fileContents);
            
            Log::debug('Trying API1 (qrserver.com)', [
                'file_size' => $fileSize,
                'file_path' => $filePath
            ]);
            
            $response = Http::timeout(30)
                ->attach('file', $fileContents, basename($filePath))
                ->post($url);

            Log::debug('API1 response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => substr($response->body(), 0, 500)
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['symbol'][0]['data'])) {
                    $qrData = $data[0]['symbol'][0]['data'];
                    if (!empty(trim($qrData))) {
                        Log::info('API1 success', ['qr_data_length' => strlen($qrData)]);
                        return $qrData;
                    }
                }
                // Check for errors in response
                if (isset($data[0]['symbol'][0]['error'])) {
                    Log::warning('API1 error', ['error' => $data[0]['symbol'][0]['error']]);
                }
            } else {
                Log::warning('API1 request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 200)
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('API1 (qrserver) exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 2 (goqr.me)
     */
    private function extractQRCodeWithAPI2(string $filePath): ?string
    {
        try {
            $url = 'https://api.goqr.me/api/read-qr-code/';
            
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['symbols'][0]['data'])) {
                    $qrData = $data['symbols'][0]['data'];
                    if (!empty(trim($qrData))) {
                        return $qrData;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API2 (goqr) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 3 (alternative method with different parameters)
     */
    private function extractQRCodeWithAPI3(string $filePath): ?string
    {
        try {
            // Try qrserver.com with different approach
            $url = 'https://api.qrserver.com/v1/read-qr-code/';
            
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath), [
                    'Content-Type' => 'image/jpeg'
                ])
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['symbol'][0]['data'])) {
                    $qrData = $data[0]['symbol'][0]['data'];
                    if (!empty(trim($qrData))) {
                        return $qrData;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API3 (alternative) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 4 (api4free.com)
     */
    private function extractQRCodeWithAPI4(string $filePath): ?string
    {
        try {
            $url = 'https://api4free.com/api/qr-reader';
            
            $response = Http::timeout(30)
                ->attach('image', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['data']) && !empty(trim($data['data']))) {
                    return $data['data'];
                }
                if (isset($data['text']) && !empty(trim($data['text']))) {
                    return $data['text'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API4 (api4free) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 5 (rapidapi - requires API key, but we try anyway)
     */
    private function extractQRCodeWithAPI5(string $filePath): ?string
    {
        try {
            // This API might require a key, but we try without it first
            $url = 'https://qr-code-reader.p.rapidapi.com/api/v1/read-qr-code';
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-RapidAPI-Key' => env('RAPIDAPI_KEY', ''),
                    'X-RapidAPI-Host' => 'qr-code-reader.p.rapidapi.com'
                ])
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['data']) && !empty(trim($data[0]['data']))) {
                    return $data[0]['data'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API5 (rapidapi) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 6 (qr-server.com - alternative to qrserver.com)
     */
    private function extractQRCodeWithAPI6(string $filePath): ?string
    {
        try {
            $url = 'https://qr-server.com/api/read-qr-code/';
            
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['symbol'][0]['data']) && !empty(trim($data[0]['symbol'][0]['data']))) {
                    return $data[0]['symbol'][0]['data'];
                }
                if (isset($data['result']) && !empty(trim($data['result']))) {
                    return $data['result'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API6 (qr-server) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 7 (qr-code-reader.com)
     */
    private function extractQRCodeWithAPI7(string $filePath): ?string
    {
        try {
            $url = 'https://api.qr-code-reader.com/v1/read-qr-code';
            
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['data']) && !empty(trim($data['data']))) {
                    return $data['data'];
                }
                if (isset($data['text']) && !empty(trim($data['text']))) {
                    return $data['text'];
                }
                if (isset($data[0]['data']) && !empty(trim($data[0]['data']))) {
                    return $data[0]['data'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API7 (qr-code-reader) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 8 (qrcode.tec-it.com)
     */
    private function extractQRCodeWithAPI8(string $filePath): ?string
    {
        try {
            // Try base64 encoding
            $base64Image = base64_encode(file_get_contents($filePath));
            $url = 'https://qrcode.tec-it.com/API/QRCode';
            
            $response = Http::timeout(30)
                ->asForm()
                ->post($url, [
                    'data' => 'data:image/jpeg;base64,' . $base64Image
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['value']) && !empty(trim($data['value']))) {
                    return $data['value'];
                }
                if (isset($data['data']) && !empty(trim($data['data']))) {
                    return $data['data'];
                }
            }

            // Try alternative method with file upload
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['value']) && !empty(trim($data['value']))) {
                    return $data['value'];
                }
                if (isset($data['data']) && !empty(trim($data['data']))) {
                    return $data['data'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API8 (qrcode.tec-it) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using Python with pyzbar (if available)
     */
    private function extractQRCodeWithPython(string $filePath): ?string
    {
        try {
            // Check if Python and pyzbar are available
            $pythonCheck = exec('python3 --version 2>&1') ?: exec('python --version 2>&1');
            if (!$pythonCheck) {
                return null;
            }

            // Create temporary Python script
            $scriptPath = sys_get_temp_dir() . '/qr_decode_' . uniqid() . '.py';
            $script = <<<'PYTHON'
import sys
from pyzbar.pyzbar import decode
from PIL import Image

try:
    img = Image.open(sys.argv[1])
    decoded_objects = decode(img)
    if decoded_objects:
        print(decoded_objects[0].data.decode('utf-8'))
        sys.exit(0)
    else:
        sys.exit(1)
except Exception as e:
    print(f"Error: {e}", file=sys.stderr)
    sys.exit(1)
PYTHON;

            file_put_contents($scriptPath, $script);

            // Run Python script
            $command = "python3 {$scriptPath} {$filePath} 2>&1";
            $output = exec($command, $outputArray, $returnCode);

            // Clean up
            if (file_exists($scriptPath)) {
                unlink($scriptPath);
            }

            if ($returnCode === 0 && !empty(trim($output))) {
                return trim($output);
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Python pyzbar failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert PDF to image for OCR processing
     */
    private function convertPdfToImage(string $pdfPath): ?string
    {
        try {
            // Check if Imagick is available and supports PDF
            if (!extension_loaded('imagick')) {
                Log::warning('Imagick not available for PDF conversion');
                return null;
            }

            $image = new \Imagick();
            $image->setResolution(300, 300); // High resolution for better OCR
            $image->readImage($pdfPath . '[0]'); // Read first page only
            
            $imagePath = 'telegram/pdf_' . uniqid() . '.jpg';
            $image->setImageFormat('jpg');
            $image->setImageCompressionQuality(95);
            $image->writeImage(Storage::disk('local')->path($imagePath));
            $image->destroy();

            return Storage::disk('local')->path($imagePath);
        } catch (\Exception $e) {
            Log::error('PDF conversion failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract text using Yandex Vision API
     */
    private function extractTextWithYandexVision(string $filePath): ?string
    {
        try {
            $apiKey = env('YANDEX_VISION_API_KEY');
            if (!$apiKey) {
                Log::debug('Yandex Vision API key not configured');
                return null;
            }

            $base64Image = base64_encode(file_get_contents($filePath));
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Api-Key ' . $apiKey,
                    'Content-Type' => 'application/json'
                ])
                ->post('https://vision.api.cloud.yandex.net/vision/v1/textDetection', [
                    'folderId' => env('YANDEX_FOLDER_ID', ''),
                    'analyzeSpecs' => [
                        [
                            'content' => $base64Image,
                            'features' => [
                                ['type' => 'TEXT_DETECTION']
                            ]
                        ]
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $text = '';
                
                if (isset($data['results'][0]['textDetection']['pages'][0]['blocks'])) {
                    foreach ($data['results'][0]['textDetection']['pages'][0]['blocks'] as $block) {
                        foreach ($block['lines'] ?? [] as $line) {
                            foreach ($line['words'] ?? [] as $word) {
                                $text .= ($word['text'] ?? '') . ' ';
                            }
                            $text .= "\n";
                        }
                    }
                }
                
                return !empty(trim($text)) ? trim($text) : null;
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Yandex Vision OCR failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract text using OCR.space API (free tier available)
     */
    private function extractTextWithOCRspace(string $filePath): ?string
    {
        try {
            $apiKey = env('OCR_SPACE_API_KEY', 'helloworld'); // Free tier key
            
            Log::info('Calling OCR.space API', ['file' => $filePath, 'file_size' => filesize($filePath)]);
            
            $response = Http::timeout(60)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post('https://api.ocr.space/parse/image', [
                    'apikey' => $apiKey,
                    'language' => 'rus', // Russian language
                    'isOverlayRequired' => false,
                    'detectOrientation' => true,
                ]);

            Log::info('OCR.space API response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body_preview' => substr($response->body(), 0, 500)
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('OCR.space response data', ['has_parsed_results' => isset($data['ParsedResults'])]);
                
                if (isset($data['ParsedResults'][0]['ParsedText'])) {
                    $text = trim($data['ParsedResults'][0]['ParsedText']);
                    Log::info('OCR.space extracted text', ['text_length' => strlen($text), 'text_preview' => substr($text, 0, 200)]);
                    return $text;
                }
                
                // Check for errors
                if (isset($data['ErrorMessage'])) {
                    Log::warning('OCR.space error', ['error' => $data['ErrorMessage']]);
                }
            } else {
                Log::warning('OCR.space API request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500)
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('OCR.space API exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Extract text using Tesseract OCR (requires tesseract installed)
     */
    private function extractTextWithTesseract(string $filePath): ?string
    {
        try {
            // Check if tesseract is available
            $tesseractPath = exec('which tesseract 2>/dev/null');
            if (!$tesseractPath) {
                Log::debug('Tesseract not found');
                return null;
            }

            // Run tesseract with Russian language
            $outputPath = sys_get_temp_dir() . '/tesseract_' . uniqid();
            $command = "tesseract {$filePath} {$outputPath} -l rus 2>/dev/null";
            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($outputPath . '.txt')) {
                $text = file_get_contents($outputPath . '.txt');
                unlink($outputPath . '.txt');
                return !empty(trim($text)) ? trim($text) : null;
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Tesseract OCR failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract text using Google Cloud Vision API
     */
    private function extractTextWithGoogleVision(string $filePath): ?string
    {
        try {
            $apiKey = env('GOOGLE_VISION_API_KEY');
            if (!$apiKey) {
                Log::debug('Google Vision API key not configured');
                return null;
            }

            $base64Image = base64_encode(file_get_contents($filePath));
            
            $response = Http::timeout(30)
                ->post("https://vision.googleapis.com/v1/images:annotate?key={$apiKey}", [
                    'requests' => [
                        [
                            'image' => [
                                'content' => $base64Image
                            ],
                            'features' => [
                                ['type' => 'TEXT_DETECTION']
                            ]
                        ]
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['responses'][0]['textAnnotations'][0]['description'])) {
                    return trim($data['responses'][0]['textAnnotations'][0]['description']);
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Google Vision OCR failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse payment amount from extracted text
     */
    private function parsePaymentAmount(string $text): ?array
    {
        try {
            Log::info('Parsing payment amount from text', [
                'text_length' => strlen($text),
                'text_preview' => substr($text, 0, 500)
            ]);
            
            // Store original text for debugging
            $originalText = $text;
            
            // Normalize text - remove extra spaces and newlines
            $text = preg_replace('/\s+/', ' ', $text);
            $textLower = mb_strtolower($text, 'UTF-8');
            
            // Patterns to find amount in Russian receipts
            $patterns = [
                // "Итого: 550 ₽" or "Итого 550 ₽" or "Итого 550 P"
                '/итого[:\s]+(\d+[\.,]?\d*)\s*[₽рубрp]/ui',
                // "Сумма: 550 ₽" or "Сумма 550 ₽" or "Сумма 550 P"
                '/сумма[:\s]+(\d+[\.,]?\d*)\s*[₽рубрp]/ui',
                // "К оплате: 550 ₽"
                '/к\s+оплате[:\s]+(\d+[\.,]?\d*)\s*[₽рубрp]/ui',
                // "Всего: 550 ₽"
                '/всего[:\s]+(\d+[\.,]?\d*)\s*[₽рубрp]/ui',
                // "Итого 550 P" (with space before P)
                '/итого\s+(\d+[\.,]?\d*)\s*[pр]/ui',
                // Just find numbers with currency symbol (₽, руб, P, р)
                '/(\d+[\.,]?\d*)\s*[₽рубрp]/ui',
                // Find "550 P" pattern (common in receipts)
                '/(\d+[\.,]?\d*)\s+p\b/ui',
                // Find large numbers (likely amounts) - but not too large
                '/(\d{2,5}[\.,]?\d*)/u',
            ];

            $amount = null;
            $date = null;

            foreach ($patterns as $index => $pattern) {
                // Try both original and lowercase text
                $testTexts = [$textLower, $text];
                foreach ($testTexts as $testText) {
                    if (preg_match($pattern, $testText, $matches)) {
                        $amountStr = str_replace(',', '.', $matches[1]);
                        $amount = (float) $amountStr;
                        
                        // Validate amount (should be reasonable)
                        if ($amount > 0 && $amount < 1000000) {
                            Log::info('Amount found using pattern', [
                                'pattern_index' => $index,
                                'pattern' => $pattern,
                                'amount' => $amount,
                                'match' => $matches[0]
                            ]);
                            break 2; // Break from both loops
                        } else {
                            Log::debug('Amount found but invalid', ['amount' => $amount]);
                        }
                    }
                }
            }

            // Try to find date
            $datePatterns = [
                '/(\d{2})[\.\/](\d{2})[\.\/](\d{4})\s+(\d{2}):(\d{2}):(\d{2})/u', // 03.02.2026 10:14:31
                '/(\d{2})[\.\/](\d{2})[\.\/](\d{4})/u', // 03.02.2026
                '/(\d{4})[\.\/-](\d{2})[\.\/-](\d{2})/u', // 2026-02-03
            ];

            foreach ($datePatterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    try {
                        if (count($matches) >= 4) {
                            if (strlen($matches[1]) === 4) {
                                // YYYY-MM-DD format
                                $dateStr = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
                            } else {
                                // DD.MM.YYYY format
                                $dateStr = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
                            }
                            
                            if (isset($matches[4]) && isset($matches[5])) {
                                $dateStr .= " {$matches[4]}:{$matches[5]}";
                                if (isset($matches[6])) {
                                    $dateStr .= ":{$matches[6]}";
                                }
                            }
                            
                            $date = $dateStr;
                            break;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }

            if ($amount) {
                return [
                    'amount' => $amount,
                    'date' => $date,
                    'currency' => 'RUB',
                    'raw_text' => substr($text, 0, 500), // Store first 500 chars for debugging
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error parsing payment amount: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse check data from QR code string
     * Russian fiscal receipt format (ФНС)
     */
    private function parseCheckData(string $qrData): ?array
    {
        try {
            // Russian fiscal receipt QR code format:
            // t=YYYYMMDDTHHMM&s=SUM&fn=FN&i=FPD&fp=FP&n=OPERATION_TYPE
            
            $params = [];
            parse_str($qrData, $params);

            if (empty($params)) {
                return null;
            }

            $checkData = [
                'date' => $this->parseDate($params['t'] ?? null),
                'sum' => $params['s'] ?? null,
                'fn' => $params['fn'] ?? null, // Fiscal number
                'fpd' => $params['i'] ?? null, // Fiscal document number
                'fp' => $params['fp'] ?? null, // Fiscal sign
                'operation_type' => $params['n'] ?? null,
                'raw_data' => $qrData,
            ];

            return $checkData;
        } catch (\Exception $e) {
            Log::error('Error parsing check data: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse date from fiscal receipt format
     */
    private function parseDate(?string $dateString): ?string
    {
        if (!$dateString) {
            return null;
        }

        try {
            // Format: YYYYMMDDTHHMM
            if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})$/', $dateString, $matches)) {
                return "{$matches[1]}-{$matches[2]}-{$matches[3]} {$matches[4]}:{$matches[5]}";
            }
            return $dateString;
        } catch (\Exception $e) {
            return $dateString;
        }
    }

    /**
     * Send check result to user
     */
    private function sendCheckResult(TelegramBot $bot, int $chatId, array $checkData): void
    {
        $message = "✅ Чек успешно обработан!\n\n";
        
        // Handle date
        $date = $checkData['date'] ?? null;
        if ($date) {
            $message .= "📅 Дата: {$date}\n";
        }
        
        // Handle amount (new OCR format) or sum (old QR format)
        $amount = $checkData['amount'] ?? $checkData['sum'] ?? null;
        if ($amount !== null) {
            // If sum is greater than 10000, it's likely in kopecks, otherwise in rubles
            if (is_numeric($amount) && $amount > 10000 && !isset($checkData['amount'])) {
                $amountFormatted = number_format($amount / 100, 2, '.', ' ') . ' ₽';
            } else {
                $amountFormatted = number_format((float)$amount, 2, '.', ' ') . ' ₽';
            }
            $message .= "💰 Сумма: {$amountFormatted}\n";
        }
        
        // Handle fiscal data (only for QR code receipts)
        if (isset($checkData['fn'])) {
            $message .= "🏪 ФН: " . ($checkData['fn'] ?? 'Не указан') . "\n";
        }
        if (isset($checkData['fpd'])) {
            $message .= "📄 ФД: " . ($checkData['fpd'] ?? 'Не указан') . "\n";
        }
        if (isset($checkData['fp'])) {
            $message .= "🔐 ФП: " . ($checkData['fp'] ?? 'Не указан') . "\n";
        }

        $this->sendMessage($bot, $chatId, $message);
    }

    /**
     * Handle callback query (button clicks)
     */
    private function handleCallbackQuery(TelegramBot $bot, array $callbackQuery): void
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $data = $callbackQuery['data'] ?? '';

        // Answer callback query
        Http::post("https://api.telegram.org/bot{$bot->token}/answerCallbackQuery", [
            'callback_query_id' => $callbackQuery['id'],
        ]);

        // Handle callback data
        // TODO: Implement callback handling if needed
    }

    /**
     * Send message to user
     */
    private function sendMessage(TelegramBot $bot, int $chatId, string $text): void
    {
        try {
            Http::post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending message: ' . $e->getMessage());
        }
    }
}
