<?php
/**
 * API endpoint для Tesseract OCR на VPS с предобработкой изображений
 * Разместить на VPS: /var/www/tesseract-api/index.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Простая авторизация через токен
$API_TOKEN = getenv('TESSERACT_API_TOKEN') ?: '6a6208d615028b15a830922270a6e05bb68448719fd614f26ee25ea7253c5090';

// Проверка токена
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($authHeader, 'Bearer ') || substr($authHeader, 7) !== $API_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Проверка метода
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Проверка Tesseract
$tesseractPath = exec('which tesseract 2>/dev/null');
if (!$tesseractPath) {
    http_response_code(500);
    echo json_encode(['error' => 'Tesseract not installed']);
    exit;
}

/**
 * Предобработка изображения для улучшения OCR
 * Минимальная обработка чтобы не испортить качество
 */
function preprocessImage($inputPath, $outputPath) {
    // Проверяем наличие Imagick
    if (!extension_loaded('imagick')) {
        copy($inputPath, $outputPath);
        return false;
    }
    
    try {
        $image = new Imagick($inputPath);
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        
        // Для больших изображений (PDF конверсии) - улучшаем контраст для мелкого текста
        if ($width > 1000 && $height > 1000) {
            // Конвертируем в grayscale для лучшего OCR
            $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            
            // Нормализация контраста
            $image->normalizeImage();
            
            // Усиливаем контраст для мелкого серого текста
            $image->contrastImage(true);
            $image->contrastImage(true);
            
            // Легкая резкость для улучшения краев текста
            $image->sharpenImage(0, 1.0);
            
            $image->setImageFormat('png');
            $image->setImageCompressionQuality(95);
            $image->writeImage($outputPath);
            $image->destroy();
            return true;
        }
        
        // Для маленьких изображений (фото с телефона) - более агрессивная обработка
        if ($width < 800 || $height < 800) {
            $scale = max(1200 / $width, 1200 / $height);
            $scale = min($scale, 2.5);
            $image->resizeImage((int)($width * $scale), (int)($height * $scale), Imagick::FILTER_LANCZOS, 1);
        }
        
        // Конвертируем в grayscale
        $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        
        // Легкая нормализация
        $image->normalizeImage();
        
        // Сохраняем
        $image->setImageFormat('png');
        $image->setImageCompressionQuality(95);
        $image->writeImage($outputPath);
        $image->destroy();
        
        return true;
    } catch (Exception $e) {
        copy($inputPath, $outputPath);
        return false;
    }
}

try {
    // Получить изображение
    $imageData = null;
    $json = null;
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imageData = file_get_contents($_FILES['image']['tmp_name']);
    } elseif (isset($_POST['image'])) {
        $imageData = base64_decode($_POST['image'], true);
    } elseif ($input = file_get_contents('php://input')) {
        $json = json_decode($input, true);
        if (isset($json['image']) && is_string($json['image'])) {
            $imageData = base64_decode($json['image'], true);
        }
    }
    
    if (!$imageData || $imageData === false) {
        http_response_code(400);
        echo json_encode(['error' => 'No image provided']);
        exit;
    }
    
    // Сохранить временные файлы
    $tempDir = sys_get_temp_dir();
    $uniqueId = uniqid();
    $inputFile = $tempDir . '/tess_input_' . $uniqueId . '.jpg';
    $processedFile = $tempDir . '/tess_processed_' . $uniqueId . '.png';
    $outputFile = $tempDir . '/tess_output_' . $uniqueId;
    
    file_put_contents($inputFile, $imageData);
    
    // Предобработка изображения
    $preprocessed = preprocessImage($inputFile, $processedFile);
    $fileToProcess = file_exists($processedFile) ? $processedFile : $inputFile;
    
    // Определить языки
    $langs = $json['langs'] ?? $_POST['langs'] ?? $_GET['langs'] ?? 'rus+eng';
    
    // Массив PSM режимов для попытки
    $psmModes = [6, 3, 4, 11];
    $bestText = '';
    $bestLength = 0;
    
    foreach ($psmModes as $psm) {
        $command = escapeshellarg($tesseractPath) . ' ' . 
                   escapeshellarg($fileToProcess) . ' ' . 
                   escapeshellarg($outputFile) . 
                   ' -l ' . escapeshellarg($langs) . 
                   ' --psm ' . $psm . ' --oem 3 2>&1';
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($outputFile . '.txt')) {
            $text = file_get_contents($outputFile . '.txt');
            @unlink($outputFile . '.txt');
            
            $textLength = strlen(trim($text));
            
            // Выбираем результат с наибольшим количеством текста
            // и наличием ключевых слов для чеков
            $score = $textLength;
            
            // Бонус за наличие ключевых слов чеков
            if (preg_match('/итого|сумма|всего|total|amount/ui', $text)) {
                $score += 500;
            }
            if (preg_match('/\d+[.,]\d{2}\s*[₽Рр]/u', $text)) {
                $score += 300;
            }
            if (preg_match('/\d{2}[.\/]\d{2}[.\/]\d{2,4}/u', $text)) {
                $score += 200;
            }
            
            if ($score > $bestLength) {
                $bestText = $text;
                $bestLength = $score;
            }
        }
    }
    
    // Если предобработка не помогла, попробуем без неё
    if (strlen(trim($bestText)) < 50 && $preprocessed && file_exists($inputFile)) {
        foreach ([6, 3] as $psm) {
            $command = escapeshellarg($tesseractPath) . ' ' . 
                       escapeshellarg($inputFile) . ' ' . 
                       escapeshellarg($outputFile) . 
                       ' -l ' . escapeshellarg($langs) . 
                       ' --psm ' . $psm . ' --oem 3 2>&1';
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputFile . '.txt')) {
                $text = file_get_contents($outputFile . '.txt');
                @unlink($outputFile . '.txt');
                
                if (strlen(trim($text)) > strlen(trim($bestText))) {
                    $bestText = $text;
                }
            }
        }
    }
    
    // Очистить временные файлы
    @unlink($inputFile);
    @unlink($processedFile);
    
    // Вернуть результат
    echo json_encode([
        'success' => true,
        'text' => trim($bestText),
        'text_length' => strlen(trim($bestText)),
        'preprocessed' => $preprocessed
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
