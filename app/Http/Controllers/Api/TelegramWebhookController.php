<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Check;
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
                Log::warning('Bot not found for update', [
                    'update_id' => $update['update_id'] ?? null,
                    'has_message' => isset($update['message']),
                    'has_callback_query' => isset($update['callback_query'])
                ]);
                return response()->json(['ok' => true]); // Return ok to Telegram
            }

            Log::info('Bot found, processing update', [
                'bot_id' => $bot->id,
                'has_message' => isset($update['message']),
                'has_callback_query' => isset($update['callback_query'])
            ]);

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
        
        Log::info('Finding bot by update', [
            'active_bots_count' => $bots->count(),
            'has_message' => isset($update['message']),
            'has_callback_query' => isset($update['callback_query'])
        ]);
        
        if ($bots->count() === 0) {
            Log::warning('No active bots found in database');
            return null;
        }
        
        // If only one bot, return it
        if ($bots->count() === 1) {
            Log::info('Using single active bot', ['bot_id' => $bots->first()->id]);
            return $bots->first();
        }
        
        // If multiple bots, we need to identify by bot_id or token
        // For now, try to get bot info from message and match
        // In production, you might want to use bot_id in webhook URL path
        
        // For now, return first active bot
        Log::info('Multiple bots found, using first active bot', ['bot_id' => $bots->first()->id]);
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
        
        // Данные отправителя для статистики
        $from = $message['from'] ?? [];
        $userData = [
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
        ];

        // Handle /start command
        if ($text && str_starts_with($text, '/start')) {
            $this->handleStartCommand($bot, $chatId);
            return;
        }

        // Handle photo (check image)
        if ($photo) {
            $this->handlePhoto($bot, $chatId, $photo, $userData);
            return;
        }

        // Handle document (check image file or PDF)
        if ($document && ($this->isImageDocument($document) || $this->isPdfDocument($document))) {
            $this->handleDocument($bot, $chatId, $document, $userData);
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
        Log::info('Handling /start command', ['bot_id' => $bot->id, 'chat_id' => $chatId]);
        
        $welcomeMessage = "👋 Привет! Я бот для обработки чеков.\n\n";
        $welcomeMessage .= "📸 Отправьте мне фото чека или PDF документ, и я извлеку сумму платежа.\n\n";
        $welcomeMessage .= "Просто отправьте фото или PDF чека, и я обработаю его!";

        $this->sendMessage($bot, $chatId, $welcomeMessage);
    }

    /**
     * Handle photo
     */
    private function handlePhoto(TelegramBot $bot, int $chatId, array $photo, array $userData = []): void
    {
        // Send "processing" message
        $this->sendMessage($bot, $chatId, '⏳ Обрабатываю чек...');

        Log::info('Processing photo', [
            'chat_id' => $chatId,
            'photo_sizes' => count($photo),
            'sizes' => array_map(fn($p) => ['width' => $p['width'] ?? 0, 'height' => $p['height'] ?? 0, 'file_size' => $p['file_size'] ?? 0], $photo)
        ]);

        // Данные для сохранения в БД
        $checkRecord = [
            'telegram_bot_id' => $bot->id,
            'chat_id' => $chatId,
            'username' => $userData['username'] ?? null,
            'first_name' => $userData['first_name'] ?? null,
            'file_type' => 'image',
        ];

        try {
            // Используем ТОЛЬКО 2 самых больших размера фото
            // Маленькие фото (thumbnail) дают плохое качество OCR
            $photoSizes = array_reverse($photo); // Start with largest
            $photoSizes = array_slice($photoSizes, 0, 2); // Только 2 самых больших
            
            $checkData = null;
            $processedFiles = [];
            $lastFilePath = null;
            $lastFileSize = null;
            $bestRawText = null;
            $bestOcrMethod = null;

            foreach ($photoSizes as $index => $photoSize) {
                $fileId = $photoSize['file_id'];
                $width = $photoSize['width'] ?? 0;
                $height = $photoSize['height'] ?? 0;
                
                // Пропускаем слишком маленькие фото
                if ($width < 300 || $height < 300) {
                    Log::info("Skipping small photo", ['width' => $width, 'height' => $height]);
                    continue;
                }
                
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
                $lastFilePath = $filePath;
                $lastFileSize = $file['file_size'] ?? null;

                // Process check using OCR
                Log::info("Starting OCR processing", ['file' => $filePath]);
                $checkData = $this->processCheckWithOCR($filePath, false);
                
                if ($checkData) {
                    Log::info("Check data successfully extracted!", ['check_data' => $checkData]);
                    
                    // Сохраняем успешный чек в БД
                    $this->saveCheckToDatabase($checkRecord, $checkData, $filePath, $lastFileSize, 'success');
                    
                    // Success! Clean up and return
                    foreach ($processedFiles as $pf) {
                        if ($pf !== $filePath) {
                            Storage::disk('local')->delete($pf);
                        }
                    }
                    $this->sendCheckResult($bot, $chatId, $checkData);
                    return;
                } else {
                    Log::warning("OCR extraction failed for photo size {$index}");
                    // НЕ переходим к меньшим фото - выходим из цикла
                    // Если большое фото не дало результат, меньшие тоже не дадут
                    break;
                }
            }

            // If we get here, all attempts failed
            Log::error("All OCR extraction attempts failed", [
                'photo_sizes_tried' => count($photoSizes),
                'files_processed' => count($processedFiles),
                'chat_id' => $chatId
            ]);
            
            // Сохраняем неуспешную попытку в БД
            $this->saveCheckToDatabase($checkRecord, null, $lastFilePath, $lastFileSize, 'failed');
            
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
    private function handleDocument(TelegramBot $bot, int $chatId, array $document, array $userData = []): void
    {
        $fileId = $document['file_id'];
        $isPdf = $this->isPdfDocument($document);

        // Send "processing" message
        $this->sendMessage($bot, $chatId, '⏳ Обрабатываю чек...');

        // Данные для сохранения в БД
        $checkRecord = [
            'telegram_bot_id' => $bot->id,
            'chat_id' => $chatId,
            'username' => $userData['username'] ?? null,
            'first_name' => $userData['first_name'] ?? null,
            'file_type' => $isPdf ? 'pdf' : 'image',
        ];

        try {
            // Get file from Telegram
            $file = $this->getFile($bot, $fileId);
            if (!$file) {
                $this->saveCheckToDatabase($checkRecord, null, null, null, 'failed');
                $this->sendMessage($bot, $chatId, '❌ Ошибка при получении файла.');
                return;
            }

            // Download file
            $filePath = $this->downloadFile($bot, $file['file_path']);
            if (!$filePath) {
                $this->saveCheckToDatabase($checkRecord, null, null, null, 'failed');
                $this->sendMessage($bot, $chatId, '❌ Ошибка при загрузке файла.');
                return;
            }

            $fileSize = $file['file_size'] ?? null;

            // Process check using OCR
            Log::info("Processing document with OCR", ['is_pdf' => $isPdf, 'file' => $filePath]);
            $checkData = $this->processCheckWithOCR($filePath, $isPdf);

            // Send result
            if ($checkData) {
                $this->saveCheckToDatabase($checkRecord, $checkData, $filePath, $fileSize, 'success');
                $this->sendCheckResult($bot, $chatId, $checkData);
            } else {
                $this->saveCheckToDatabase($checkRecord, null, $filePath, $fileSize, 'failed');
                $this->sendMessage($bot, $chatId, '❌ Не удалось распознать текст на чеке. Убедитесь, что фото четкое и текст хорошо виден. Попробуйте отправить другое фото или PDF.');
                Storage::disk('local')->delete($filePath);
            }
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
     * Сохранить чек в базу данных для статистики
     */
    private function saveCheckToDatabase(array $baseData, ?array $checkData, ?string $filePath, ?int $fileSize, string $status): void
    {
        try {
            $amountFound = isset($checkData['amount']) && $checkData['amount'] !== null;
            $dateFound = isset($checkData['date']) && $checkData['date'] !== null;
            
            // Определяем финальный статус
            if ($status === 'success') {
                if ($amountFound && $dateFound) {
                    $finalStatus = 'success';
                } elseif ($amountFound || $dateFound) {
                    $finalStatus = 'partial';
                } else {
                    $finalStatus = 'failed';
                }
            } else {
                $finalStatus = 'failed';
            }
            
            Check::create([
                'telegram_bot_id' => $baseData['telegram_bot_id'],
                'chat_id' => $baseData['chat_id'],
                'username' => $baseData['username'],
                'first_name' => $baseData['first_name'],
                'file_path' => $filePath,
                'file_type' => $baseData['file_type'] ?? 'image',
                'file_size' => $fileSize,
                'amount' => $checkData['amount'] ?? null,
                'currency' => $checkData['currency'] ?? 'RUB',
                'check_date' => isset($checkData['date']) ? $checkData['date'] : null,
                'ocr_method' => $checkData['ocr_method'] ?? null,
                'raw_text' => isset($checkData['raw_text']) ? substr($checkData['raw_text'], 0, 5000) : null,
                'text_length' => $checkData['text_length'] ?? null,
                'readable_ratio' => $checkData['readable_ratio'] ?? null,
                'status' => $finalStatus,
                'amount_found' => $amountFound,
                'date_found' => $dateFound,
            ]);
            
            Log::info('Check saved to database', [
                'status' => $finalStatus,
                'amount_found' => $amountFound,
                'date_found' => $dateFound,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save check to database: ' . $e->getMessage());
        }
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
            // Tesseract first (if installed) - local, fast, no API limits
            // Then remote Tesseract API, then external APIs as fallback
            // OCR methods - try multiple, use first successful result with enough text
            $ocrMethods = [
                'extractTextWithTesseract',       // Local - fastest, no limits
                'extractTextWithOCRspace',        // OCR.space - good for Russian text
                'extractTextWithRemoteTesseract', // Remote VPS Tesseract API
                'extractTextWithGoogleVision',    // Paid but reliable
            ];

            $extractedText = null;
            $usedOcrMethod = null;
            $ocrTextLength = null;
            $ocrReadableRatio = null;
            
            foreach ($ocrMethods as $method) {
                try {
                    Log::info("Trying OCR method: {$method}", ['file' => $fullPath]);
                    $text = $this->$method($fullPath);
                    if ($text && !empty(trim($text))) {
                        // Check text quality - should have reasonable amount of readable characters
                        $cleanText = trim($text);
                        $textLen = mb_strlen($cleanText, 'UTF-8');
                        
                        // Count readable characters (Cyrillic, Latin, digits)
                        $readableChars = preg_match_all('/[а-яА-ЯёЁa-zA-Z0-9]/u', $cleanText);
                        $readableRatio = $textLen > 0 ? $readableChars / $textLen : 0;
                        
                        Log::info("Text extracted using {$method}", [
                            'text_length' => $textLen,
                            'readable_chars' => $readableChars,
                            'readable_ratio' => round($readableRatio, 2),
                            'text_preview' => substr($cleanText, 0, 300)
                        ]);
                        
                        // Accept if: enough text AND reasonable readable ratio
                        // For receipts we expect at least 100 chars and 30% readable
                        if ($textLen >= 100 && $readableRatio >= 0.30) {
                            $extractedText = $text;
                            $usedOcrMethod = $method;
                            $ocrTextLength = $textLen;
                            $ocrReadableRatio = $readableRatio;
                            break;
                        } elseif ($textLen >= 50 && $readableRatio >= 0.40) {
                            // Shorter but more readable is also OK
                            $extractedText = $text;
                            $usedOcrMethod = $method;
                            $ocrTextLength = $textLen;
                            $ocrReadableRatio = $readableRatio;
                            break;
                        } else {
                            Log::warning("OCR text quality too low, trying next method", [
                                'method' => $method,
                                'text_length' => $textLen,
                                'readable_ratio' => round($readableRatio, 2)
                            ]);
                        }
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
                // Добавляем информацию об OCR методе для статистики
                $checkData['ocr_method'] = $usedOcrMethod;
                $checkData['text_length'] = $ocrTextLength;
                $checkData['readable_ratio'] = $ocrReadableRatio;
                
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
            // Use higher DPI for better text quality
            $image->setResolution(450, 450);
            $image->readImage($pdfPath . '[0]'); // Read first page only
            
            // Convert to RGB colorspace for better OCR
            $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            
            // Enhance image quality for OCR
            $image->setImageFormat('png'); // PNG preserves text better than JPG
            $image->setImageCompressionQuality(95);
            
            // Convert to grayscale for OCR
            $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            
            // Improve contrast
            $image->normalizeImage();
            $image->contrastImage(true);
            
            // Sharpen text edges
            $image->sharpenImage(0, 1.5);
            
            // Resize if too large
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            Log::info('PDF image dimensions', ['width' => $width, 'height' => $height]);
            if ($width > 3500 || $height > 3500) {
                $image->scaleImage(3500, 3500, true);
            }
            
            $imagePath = 'telegram/pdf_' . uniqid() . '.png';
            $image->writeImage(Storage::disk('local')->path($imagePath));
            $image->destroy();

            Log::info('PDF converted to image', [
                'pdf_path' => $pdfPath,
                'image_path' => $imagePath,
                'resolution' => '450 DPI',
                'format' => 'PNG grayscale'
            ]);

            return Storage::disk('local')->path($imagePath);
        } catch (\Exception $e) {
            Log::error('PDF conversion failed: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
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
            $fileSize = filesize($filePath);
            
            // Skip if file is too large (over 1MB)
            if ($fileSize > 1024 * 1024) {
                Log::warning('File too large for OCR.space', ['file_size' => $fileSize]);
                return null;
            }
            
            Log::info('Calling OCR.space API', ['file' => $filePath, 'file_size' => $fileSize]);
            
            // Try base64 method first (faster and more reliable)
            $fileContents = file_get_contents($filePath);
            $base64Image = base64_encode($fileContents);
            
            $response = Http::timeout(30)
                ->asForm()
                ->post('https://api.ocr.space/parse/imagebase64', [
                    'apikey' => $apiKey,
                    'base64Image' => 'data:image/jpeg;base64,' . $base64Image,
                    'language' => 'rus',
                    'isOverlayRequired' => 'false',
                    'detectOrientation' => 'true',
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

            // If base64 method failed, try multipart as fallback
            if (!$response->successful() || !isset($response->json()['ParsedResults'])) {
                Log::info('Trying OCR.space with multipart method');
                $response = Http::timeout(30)
                    ->asMultipart()
                    ->attach('file', $fileContents, basename($filePath))
                    ->post('https://api.ocr.space/parse/image', [
                        'apikey' => $apiKey,
                        'language' => 'rus',
                        'isOverlayRequired' => 'false',
                        'detectOrientation' => 'true',
                    ]);
            }

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
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('OCR.space API timeout/connection error: ' . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            Log::error('OCR.space API exception: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500)
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
            // First try system-wide installation
            $tesseractPath = exec('which tesseract 2>/dev/null');
            
            // If not found, try local installation in project
            if (!$tesseractPath) {
                $projectLocalTesseract = base_path('local/tesseract/bin/tesseract');
                if (file_exists($projectLocalTesseract) && is_executable($projectLocalTesseract)) {
                    $tesseractPath = $projectLocalTesseract;
                    Log::info('Using local Tesseract from project directory');
                }
            }
            
            // If still not found, try home directory
            if (!$tesseractPath) {
                $homeTesseract = getenv('HOME') . '/tesseract-local/bin/tesseract';
                if (file_exists($homeTesseract) && is_executable($homeTesseract)) {
                    $tesseractPath = $homeTesseract;
                    Log::info('Using local Tesseract from home directory');
                }
            }
            
            if (!$tesseractPath) {
                Log::debug('Tesseract not found - install system-wide with: sudo apt-get install tesseract-ocr tesseract-ocr-rus');
                Log::debug('Or install locally in project/local/tesseract/ or ~/tesseract-local/');
                return null;
            }

            Log::info('Using Tesseract OCR', [
                'tesseract_path' => $tesseractPath,
                'file' => $filePath,
                'file_size' => filesize($filePath)
            ]);

            // Preprocess image for better OCR results
            $preprocessedPath = $this->preprocessImageForTesseract($filePath);
            if ($preprocessedPath) {
                // Convert relative path to full path
                $imageToProcess = Storage::disk('local')->path($preprocessedPath);
            } else {
                $imageToProcess = $filePath;
            }

            // Check if Russian and English languages are available
            $langsOutput = exec(escapeshellarg($tesseractPath) . ' --list-langs 2>&1', $langsArray, $langsReturnCode);
            $hasRussian = false;
            $hasEnglish = false;
            if ($langsReturnCode === 0) {
                foreach ($langsArray as $line) {
                    $line = trim($line);
                    if ($line === 'rus') {
                        $hasRussian = true;
                    }
                    if ($line === 'eng') {
                        $hasEnglish = true;
                    }
                }
            }

            // Build language parameter - use both Russian and English if available
            $langParam = '';
            if ($hasRussian && $hasEnglish) {
                $langParam = '-l rus+eng';
            } elseif ($hasRussian) {
                $langParam = '-l rus';
            } elseif ($hasEnglish) {
                $langParam = '-l eng';
            } else {
                Log::warning('No language packs found for Tesseract. Install with: sudo apt-get install tesseract-ocr-rus tesseract-ocr-eng');
            }

            // Run tesseract with optimized parameters for document recognition
            // --psm 6: Assume a single uniform block of text (good for receipts)
            // --psm 4: Assume a single column of text of variable sizes
            // --oem 3: Default, based on what is available (LSTM if available)
            $outputPath = sys_get_temp_dir() . '/tesseract_' . uniqid();
            
            // Try PSM 6 first (single uniform block) - best for receipts
            $command = escapeshellarg($tesseractPath) . " " . escapeshellarg($imageToProcess) . " " . escapeshellarg($outputPath) . 
                       " {$langParam} --psm 6 --oem 3 2>&1";
            
            Log::debug('Running Tesseract command', ['command' => $command]);
            
            exec($command, $output, $returnCode);

            $text = '';
            if ($returnCode === 0 && file_exists($outputPath . '.txt')) {
                $text = file_get_contents($outputPath . '.txt');
                unlink($outputPath . '.txt');
            }

            // If first attempt failed or returned little text, try PSM 4 (single column)
            if (empty(trim($text)) || strlen(trim($text)) < 10) {
                Log::debug('Tesseract PSM 6 returned little text, trying PSM 4');
                $command = escapeshellarg($tesseractPath) . " " . escapeshellarg($imageToProcess) . " " . escapeshellarg($outputPath) . 
                           " {$langParam} --psm 4 --oem 3 2>&1";
                exec($command, $output, $returnCode);
                
                if ($returnCode === 0 && file_exists($outputPath . '.txt')) {
                    $text = file_get_contents($outputPath . '.txt');
                    unlink($outputPath . '.txt');
                }
            }

            // Clean up preprocessed image if it was created
            if ($preprocessedPath && $preprocessedPath !== $filePath) {
                $fullPreprocessedPath = Storage::disk('local')->path($preprocessedPath);
                if (file_exists($fullPreprocessedPath)) {
                    @unlink($fullPreprocessedPath);
                }
            }

            if (!empty(trim($text))) {
                Log::info('Tesseract extracted text successfully', [
                    'text_length' => strlen($text),
                    'text_preview' => substr($text, 0, 200)
                ]);
                return trim($text);
            } else {
                Log::debug('Tesseract returned empty text', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", array_slice($output, 0, 5))
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Tesseract OCR exception: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            return null;
        }
    }

    /**
     * Preprocess image specifically for Tesseract OCR
     * Optimizes image for better text recognition
     */
    private function preprocessImageForTesseract(string $filePath): ?string
    {
        try {
            // Check if Imagick is available
            if (!extension_loaded('imagick')) {
                Log::debug('Imagick not available for image preprocessing');
                return null;
            }

            $image = new \Imagick($filePath);
            
            // Get image dimensions
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            
            // Resize if image is too large (Tesseract works better with 300-400 DPI)
            // If image is smaller than 1000px, scale it up
            if ($width < 1000 || $height < 1000) {
                $scale = max(1000 / $width, 1000 / $height);
                $newWidth = (int)($width * $scale);
                $newHeight = (int)($height * $scale);
                $image->resizeImage($newWidth, $newHeight, \Imagick::FILTER_LANCZOS, 1);
                Log::debug('Image resized for Tesseract', [
                    'original' => "{$width}x{$height}",
                    'new' => "{$newWidth}x{$newHeight}"
                ]);
            }
            
            // Convert to grayscale (better for OCR)
            $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            
            // Enhance contrast using normalize
            $image->normalizeImage();
            
            // Increase contrast further
            $image->contrastImage(1);
            
            // Sharpen image for better text recognition
            $image->sharpenImage(0, 1.5);
            
            // Apply adaptive threshold (binarization) - very important for OCR
            // This converts image to black and white, removing noise
            $image->thresholdImage(0.5);
            
            // Reduce noise
            $image->despeckleImage();
            
            // Save preprocessed image
            $processedPath = 'telegram/preprocessed_' . uniqid() . '.jpg';
            $image->setImageFormat('jpg');
            $image->setImageCompressionQuality(95);
            $image->writeImage(Storage::disk('local')->path($processedPath));
            $image->destroy();

            Log::debug('Image preprocessed for Tesseract', ['processed_path' => $processedPath]);
            return $processedPath;
        } catch (\Exception $e) {
            Log::debug('Image preprocessing for Tesseract failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract text using remote Tesseract API (on VPS)
     */
    private function extractTextWithRemoteTesseract(string $filePath): ?string
    {
        try {
            $remoteUrl = env('TESSERACT_REMOTE_URL', 'http://89.169.39.244:8080/');
            $remoteToken = env('TESSERACT_REMOTE_TOKEN');
            
            if (!$remoteUrl || !$remoteToken) {
                Log::debug('Remote Tesseract API not configured');
                return null;
            }
            
            $fileContents = file_get_contents($filePath);
            $base64Image = base64_encode($fileContents);
            
            Log::info('Calling remote Tesseract API', [
                'url' => $remoteUrl,
                'file' => $filePath,
                'file_size' => strlen($fileContents)
            ]);
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $remoteToken,
                    'Content-Type' => 'application/json'
                ])
                ->post($remoteUrl, [
                    'image' => $base64Image,
                    'langs' => 'rus+eng'
                ]);
            
            Log::info('Remote Tesseract API response', [
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['success']) && $data['success'] && !empty($data['text'])) {
                    Log::info('Remote Tesseract extracted text', [
                        'text_length' => strlen($data['text']),
                        'text_preview' => substr($data['text'], 0, 200)
                    ]);
                    return trim($data['text']);
                } else {
                    Log::debug('Remote Tesseract returned empty text');
                }
            } else {
                Log::warning('Remote Tesseract API request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500)
                ]);
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Remote Tesseract OCR exception: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
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
            
            // Normalize text - preserve line breaks for better context
            $text = preg_replace('/\r\n|\r/', "\n", $text);
            $textLower = mb_strtolower($text, 'UTF-8');
            
            // Extract date first to exclude it from amount search
            $date = null;
            
            // Russian month names mapping (including common OCR errors)
            $russianMonths = [
                'января' => '01', 'январь' => '01', 'янв' => '01',
                'февраля' => '02', 'февраль' => '02', 'фев' => '02',
                'фезраля' => '02', 'фезрапя' => '02', 'феврапя' => '02', // OCR errors
                'марта' => '03', 'март' => '03', 'мар' => '03',
                'апреля' => '04', 'апрель' => '04', 'апр' => '04',
                'anреля' => '04', 'апрепя' => '04', // OCR errors
                'мая' => '05', 'май' => '05',
                'июня' => '06', 'июнь' => '06', 'июн' => '06',
                'июля' => '07', 'июль' => '07', 'июл' => '07',
                'августа' => '08', 'август' => '08', 'авг' => '08',
                'аигуста' => '08', 'авгyста' => '08', // OCR errors
                'сентября' => '09', 'сентябрь' => '09', 'сен' => '09',
                'октября' => '10', 'октябрь' => '10', 'окт' => '10',
                'ноября' => '11', 'ноябрь' => '11', 'ноя' => '11',
                'декабря' => '12', 'декабрь' => '12', 'дек' => '12',
            ];
            
            // Try Russian month format first: "3 февраля 2026 в 14:38" or "3 февраля 2026"
            $monthPattern = implode('|', array_keys($russianMonths));
            
            // Pattern: "3 февраля 2026 в 14:38" or "3 февраля 2026 14:38:00"
            if (preg_match('/(\d{1,2})\s+(' . $monthPattern . ')\s+(\d{4})(?:\s+(?:в\s+)?(\d{1,2}):(\d{2})(?::(\d{2}))?)?/ui', $text, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $monthName = mb_strtolower($matches[2], 'UTF-8');
                $month = $russianMonths[$monthName] ?? '01';
                $year = $matches[3];
                
                $dateStr = "{$year}-{$month}-{$day}";
                
                if (isset($matches[4]) && isset($matches[5])) {
                    $hour = str_pad($matches[4], 2, '0', STR_PAD_LEFT);
                    $minute = $matches[5];
                    $dateStr .= " {$hour}:{$minute}";
                    if (isset($matches[6])) {
                        $dateStr .= ":{$matches[6]}";
                    }
                }
                
                $date = $dateStr;
                Log::debug('Parsed Russian month date', ['date' => $date, 'match' => $matches[0]]);
            }
            
            // Try "сегодня в HH:MM" or "вчера в HH:MM" format
            if (!$date) {
                if (preg_match('/сегодня\s+(?:в\s+)?(\d{1,2}):(\d{2})/ui', $text, $matches)) {
                    $today = date('Y-m-d');
                    $hour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $minute = $matches[2];
                    $date = "{$today} {$hour}:{$minute}";
                    Log::debug('Parsed "today" date', ['date' => $date, 'match' => $matches[0]]);
                } elseif (preg_match('/вчера\s+(?:в\s+)?(\d{1,2}):(\d{2})/ui', $text, $matches)) {
                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                    $hour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $minute = $matches[2];
                    $date = "{$yesterday} {$hour}:{$minute}";
                    Log::debug('Parsed "yesterday" date', ['date' => $date, 'match' => $matches[0]]);
                }
            }
            
            // If no date found, try numeric patterns
            if (!$date) {
                $datePatterns = [
                    '/(\d{2})[\.\/](\d{2})[\.\/](\d{4})\s+(\d{2}):(\d{2}):(\d{2})/u', // 03.02.2026 10:14:31
                    '/(\d{2})[\.\/](\d{2})[\.\/](\d{4})\s+(\d{2}):(\d{2})/u', // 03.02.2026 10:14
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
                                // Remove date from text to avoid matching it as amount
                                $text = preg_replace($pattern, '', $text);
                                $textLower = mb_strtolower($text, 'UTF-8');
                                break;
                            }
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }
            }
            
            // ---- Amount extraction with scoring (prevents picking INN/account numbers) ----
            $amount = null;

            // Keywords that indicate payment amounts (including Sberbank-specific)
            $keywords = [
                'итого', 'сумма', 'к оплате', 'всего',
                'сумма в валюте карты', 'сумма в валюте операции',
                'сумма в валюте', 'в валюте карты', 'в валюте операции',
                'оплата', 'платёж', 'платеж'
            ];
            $badContextWords = [
                'инн', 'бик', 'кпп', 'огрн', 'р/с', 'счет', 'счёт', 
                'идентификатор', 'сбп', 'телефон',
                'код авторизации', 'авторизации', 'квитанция №', 'квитанции',
            ];
            
            // Normalize spaces in text for better number matching (convert all whitespace to single space)
            $textNormalized = preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\r\n]+/u', ' ', $text);
            
            // Fix common OCR errors: replace letter O with digit 0 in number contexts
            // "25 ООО" -> "25 000", "1О ООО" -> "10 000"
            $textNormalized = preg_replace_callback(
                '/(\d+)\s*([ОоOo]+)\s*([ОоOoрРеЕ₽])/u',
                function ($m) {
                    $zeros = preg_replace('/[ОоOo]/u', '0', $m[2]);
                    return $m[1] . ' ' . $zeros . ' ' . $m[3];
                },
                $textNormalized
            );
            // Also fix standalone "ООО" after numbers: "25 ООО р" -> "25 000 р"
            $textNormalized = preg_replace('/(\d+)\s+[ОоOo]{3}\s+([рРеЕ₽Pp])/u', '$1 000 $2', $textNormalized);
            
            // ==========================================
            // ПОИСК СУММЫ - улучшенная логика
            // ==========================================
            // OCR часто путает ₽ с "е", "e", "P", "р", "R", "Р"
            $currencyPattern = '[₽РрPpеeЕER]';
            
            // Нормализуем текст для поиска
            $textForSearch = $text; // Оригинальный текст с переносами
            $textOneLine = preg_replace('/[\r\n]+/', ' ', $text); // Текст в одну строку
            
            Log::debug('Searching for amount in text', [
                'text_length' => mb_strlen($text),
                'first_500_chars' => mb_substr($textOneLine, 0, 500)
            ]);
            
            $directAmount = null;
            
            // 1. Ищем паттерн "Итого X XXX ₽" или "Итого\nX XXX ₽"
            // Поддерживаем разные форматы чисел: "10 000", "10000", "10,000.00"
            $amountRegex = '(\d{1,3}(?:[\s\x{00A0}]+\d{3})*(?:[.,]\d{2})?|\d+(?:[.,]\d{2})?)';
            
            $directPatterns = [
                // Итого + число + валюта (может быть на разных строках)
                '/итого[^\d]{0,30}' . $amountRegex . '\s*' . $currencyPattern . '/ui',
                // Сумма + число + валюта
                '/сумма[^\d]{0,30}' . $amountRegex . '\s*' . $currencyPattern . '/ui',
                // Число + валюта рядом с "Итого" в пределах 50 символов
                '/итого.{0,50}?' . $amountRegex . '\s*' . $currencyPattern . '/uis',
            ];
            
            foreach ($directPatterns as $pattern) {
                if (preg_match($pattern, $textOneLine, $match)) {
                    $numStr = preg_replace('/[\s\x{00A0}]+/u', '', $match[1]);
                    $numStr = str_replace(',', '.', $numStr);
                    if (is_numeric($numStr)) {
                        $val = (float) $numStr;
                        if ($val >= 100 && $val < 10000000) {
                            $directAmount = $val;
                            Log::info('Found direct amount with Итого/Сумма', [
                                'pattern' => $pattern,
                                'amount' => $directAmount,
                                'raw_match' => mb_substr($match[0], 0, 100)
                            ]);
                            break;
                        }
                    }
                }
            }
            
            // 2. Если не нашли с ключевыми словами - ищем все суммы с валютой
            if (!$directAmount) {
                $allAmountsWithCurrency = [];
                
                // Паттерн: число (формат "X XXX" или "XXXXX") + символ валюты
                if (preg_match_all('/' . $amountRegex . '\s*' . $currencyPattern . '/ui', $textOneLine, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    foreach ($matches as $match) {
                        $numStr = preg_replace('/[\s\x{00A0}]+/u', '', $match[1][0]);
                        $numStr = str_replace(',', '.', $numStr);
                        if (is_numeric($numStr)) {
                            $val = (float) $numStr;
                            // Исключаем слишком маленькие и слишком большие значения
                            if ($val >= 100 && $val < 10000000) {
                                $allAmountsWithCurrency[] = [
                                    'amount' => $val,
                                    'raw' => $match[0][0],
                                    'pos' => $match[0][1]
                                ];
                            }
                        }
                    }
                }
                
                Log::debug('All amounts with currency found', ['amounts' => $allAmountsWithCurrency]);
                
                // Выбираем наибольшую сумму (обычно это итого)
                if (!empty($allAmountsWithCurrency)) {
                    usort($allAmountsWithCurrency, fn($a, $b) => $b['amount'] <=> $a['amount']);
                    $directAmount = $allAmountsWithCurrency[0]['amount'];
                    Log::info('Selected largest amount with currency', [
                        'amount' => $directAmount,
                        'raw' => $allAmountsWithCurrency[0]['raw']
                    ]);
                }
            }
            
            // 3. Если всё ещё не нашли - пробуем без символа валюты, но рядом с ключевыми словами
            if (!$directAmount) {
                $keywordPatterns = [
                    '/итого[:\s]+' . $amountRegex . '/ui',
                    '/сумма[:\s]+' . $amountRegex . '/ui',
                    '/к\s*оплате[:\s]+' . $amountRegex . '/ui',
                    '/всего[:\s]+' . $amountRegex . '/ui',
                ];
                
                foreach ($keywordPatterns as $pattern) {
                    if (preg_match($pattern, $textOneLine, $match)) {
                        $numStr = preg_replace('/[\s\x{00A0}]+/u', '', $match[1]);
                        $numStr = str_replace(',', '.', $numStr);
                        if (is_numeric($numStr)) {
                            $val = (float) $numStr;
                            if ($val >= 100 && $val < 10000000) {
                                $directAmount = $val;
                                Log::info('Found amount near keyword (no currency symbol)', [
                                    'pattern' => $pattern,
                                    'amount' => $directAmount,
                                    'raw_match' => $match[0]
                                ]);
                                break;
                            }
                        }
                    }
                }
            }

            // Find all numeric candidates (with optional thousands separators and decimals)
            // Pattern matches: "10 000", "10000", "1 234 567", "123,45", "123.45"
            if (preg_match_all('/\d{1,3}(?:[\s]\d{3})+(?:[.,]\d{2})?|\d+(?:[.,]\d{2})?/u', $textNormalized, $numMatches, PREG_OFFSET_CAPTURE)) {
                $candidates = [];

                foreach ($numMatches[0] as [$rawNum, $pos]) {
                    $rawNumTrim = trim($rawNum);

                    // Skip obvious dates (02.02, 03.02.2026, 03022016)
                    if (preg_match('/^\d{1,2}[.\/]\d{1,2}([.\/]\d{2,4})?$/u', $rawNumTrim)) {
                        continue;
                    }
                    if (preg_match('/^\d{8}$/u', $rawNumTrim)) {
                        continue;
                    }
                    
                    // Skip numbers that are part of time format (HH:MM)
                    $charAfter = substr($textNormalized, $pos + strlen($rawNumTrim), 1);
                    $charBefore = $pos > 0 ? substr($textNormalized, $pos - 1, 1) : '';
                    if ($charAfter === ':' || $charBefore === ':') {
                        continue;
                    }
                    
                    // Skip numbers that look like part of receipt/transaction numbers (sequences of digits with dashes)
                    $contextAround = substr($textNormalized, max(0, $pos - 10), strlen($rawNumTrim) + 20);
                    if (preg_match('/\d+-\d+-\d+/', $contextAround)) {
                        continue;
                    }

                    // Normalize number - remove spaces and convert comma to dot
                    $normalized = preg_replace('/[\s\x{00A0}]+/u', '', $rawNumTrim);
                    $normalized = str_replace(',', '.', $normalized);

                    if (!is_numeric($normalized)) {
                        continue;
                    }

                    $val = (float) $normalized;
                    if ($val < 1 || $val > 1000000) {
                        continue;
                    }

                    // Context window around number
                    $winStart = max(0, $pos - 80);
                    $winLen = min(strlen($textNormalized) - $winStart, 160);
                    $context = mb_strtolower(substr($textNormalized, $winStart, $winLen), 'UTF-8');

                    // Reject if near known non-amount fields
                    $isBad = false;
                    foreach ($badContextWords as $w) {
                        if (str_contains($context, $w)) {
                            $isBad = true;
                            break;
                        }
                    }
                    if ($isBad) {
                        continue;
                    }

                    // Currency proximity - check if ₽/Р immediately follows the number (within 3 chars)
                    $afterClose = substr($textNormalized, $pos + strlen($rawNumTrim), 5);
                    $hasCurrencyClose = (bool) preg_match('/^\s*[₽РрPp]/ui', $afterClose);
                    
                    // Also check broader context for currency
                    $after = substr($textNormalized, $pos, 30);
                    $before = substr($textNormalized, max(0, $pos - 30), 30);
                    $hasCurrencyBroad = (bool) preg_match('/(₽|руб)/ui', $after) || (bool) preg_match('/(₽|руб)/ui', $before);

                    // Keyword proximity scoring
                    $score = 0;
                    
                    // Strong bonus for currency immediately after number
                    if ($hasCurrencyClose) {
                        $score += 10;
                    } elseif ($hasCurrencyBroad) {
                        $score += 3;
                    }
                    
                    // Strong bonus for key receipt keywords
                    if (str_contains($context, 'итого')) {
                        $score += 15;
                    }
                    if (str_contains($context, 'сумма') && !str_contains($context, 'комисси')) {
                        $score += 12;
                    }
                    foreach ($keywords as $kw) {
                        if (str_contains($context, $kw)) {
                            $score += 3;
                        }
                    }

                    // Prefer larger reasonable amounts (receipts usually > 50)
                    if ($val >= 100) {
                        $score += 3;
                    } elseif ($val >= 50) {
                        $score += 2;
                    } elseif ($val >= 10) {
                        $score += 1;
                    }
                    
                    // Penalize very small numbers (likely to be dates/times/counts)
                    if ($val < 25 && !$hasCurrencyClose) {
                        $score -= 5;
                    }
                    
                    if (preg_match('/^\d{6,}$/u', $normalized) && !$hasCurrencyClose) {
                        // large raw number without currency is suspicious (like INN/account)
                        $score -= 4;
                    }

                    $candidates[] = [
                        'amount' => $val,
                        'raw' => $rawNumTrim,
                        'pos' => $pos,
                        'score' => $score,
                        'has_currency' => $hasCurrencyClose || $hasCurrencyBroad,
                    ];
                }

                if (!empty($candidates)) {
                    usort($candidates, function ($a, $b) {
                        // score desc, then amount desc
                        $cmp = ($b['score'] <=> $a['score']);
                        if ($cmp !== 0) return $cmp;
                        return $b['amount'] <=> $a['amount'];
                    });

                    $best = $candidates[0];
                    
                    // Minimum score threshold - if too low, don't trust the result
                    // Score of 10+ means we found keywords like "итого" or "сумма" near the number
                    $minScoreThreshold = 8;
                    
                    Log::info('Amount selected by scoring', [
                        'amount' => $best['amount'],
                        'raw' => $best['raw'],
                        'score' => $best['score'],
                        'has_currency' => $best['has_currency'],
                        'min_threshold' => $minScoreThreshold,
                        'top3' => array_slice($candidates, 0, 3),
                    ]);
                    
                    if ($best['score'] >= $minScoreThreshold) {
                        $amount = $best['amount'];
                    } else {
                        Log::warning('Amount score too low, result unreliable', [
                            'best_score' => $best['score'],
                            'threshold' => $minScoreThreshold,
                            'best_amount' => $best['amount']
                        ]);
                    }
                }
            }
            
            // Приоритет: directAmount (найдена рядом с ключевыми словами) > scored amount
            // directAmount более надежна т.к. привязана к контексту (Итого, Сумма и т.д.)
            if ($directAmount) {
                Log::info('Using direct pattern amount (highest priority)', [
                    'direct_amount' => $directAmount,
                    'scored_amount' => $amount
                ]);
                $amount = $directAmount;
            }
            
            // Если directAmount не найдена, используем scored amount только если score достаточно высок
            // Если и score низкий - всё равно возвращаем null

            if ($amount) {
                Log::info('Final amount selected', ['amount' => $amount, 'date' => $date]);
                return [
                    'sum' => $amount,
                    'amount' => $amount,
                    'date' => $date,
                    'currency' => 'RUB',
                    'raw_text' => substr($originalText, 0, 500),
                ];
            }

            Log::warning('No reliable amount found in text');
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
            Log::info('Sending message to Telegram', [
                'bot_id' => $bot->id,
                'chat_id' => $chatId,
                'text_length' => strlen($text)
            ]);
            
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);

            if ($response->successful()) {
                Log::info('Message sent successfully');
            } else {
                Log::error('Failed to send message', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending message: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
