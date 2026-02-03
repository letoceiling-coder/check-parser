<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Check;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class CheckController extends Controller
{
    /**
     * Получить список чеков с пагинацией и фильтрацией
     */
    public function index(Request $request): JsonResponse
    {
        $query = Check::with('telegramBot')
            ->orderBy('created_at', 'desc');

        // Фильтр по статусу
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Фильтр по OCR методу
        if ($request->has('ocr_method') && $request->ocr_method !== 'all') {
            $query->where('ocr_method', $request->ocr_method);
        }

        // Фильтр по дате
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Поиск по имени пользователя
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('chat_id', 'like', "%{$search}%");
            });
        }

        $checks = $query->paginate($request->get('per_page', 20));

        return response()->json($checks);
    }

    /**
     * Получить один чек
     */
    public function show(int $id): JsonResponse
    {
        $check = Check::with('telegramBot')->findOrFail($id);
        return response()->json($check);
    }

    /**
     * Обновить чек (ручная коррекция)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $check = Check::findOrFail($id);

        $validated = $request->validate([
            'corrected_amount' => 'nullable|numeric|min:0',
            'corrected_date' => 'nullable|date',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $check->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Чек обновлен',
            'check' => $check->fresh()
        ]);
    }

    /**
     * Удалить чек
     */
    public function destroy(int $id): JsonResponse
    {
        $check = Check::findOrFail($id);
        
        // Удаляем файл если есть
        if ($check->file_path && Storage::disk('local')->exists($check->file_path)) {
            Storage::disk('local')->delete($check->file_path);
        }

        $check->delete();

        return response()->json([
            'success' => true,
            'message' => 'Чек удален'
        ]);
    }

    /**
     * Получить статистику по чекам
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total' => Check::count(),
            'success' => Check::where('status', 'success')->count(),
            'partial' => Check::where('status', 'partial')->count(),
            'failed' => Check::where('status', 'failed')->count(),
            
            // По OCR методам
            'by_ocr_method' => Check::selectRaw('ocr_method, count(*) as count, 
                SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as success_count')
                ->groupBy('ocr_method')
                ->get(),
            
            // Общая сумма
            'total_amount' => Check::whereNotNull('amount')->sum('amount'),
            
            // Средняя readable_ratio по методам
            'avg_readable_ratio' => Check::selectRaw('ocr_method, AVG(readable_ratio) as avg_ratio')
                ->whereNotNull('readable_ratio')
                ->groupBy('ocr_method')
                ->get(),
            
            // За последние 7 дней
            'last_7_days' => Check::selectRaw('DATE(created_at) as date, count(*) as count,
                SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as success_count')
                ->where('created_at', '>=', now()->subDays(7))
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
        ];

        // Процент успешности
        $stats['success_rate'] = $stats['total'] > 0 
            ? round(($stats['success'] / $stats['total']) * 100, 1) 
            : 0;

        return response()->json($stats);
    }

    /**
     * Получить файл чека
     * Поддерживает token в query string для iframe
     */
    public function file(Request $request, int $id)
    {
        // Проверяем авторизацию - token может быть в header или query string
        $token = $request->query('token') ?? $request->bearerToken();
        
        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        // Проверяем токен через Sanctum
        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['error' => 'Invalid token'], 401);
        }
        
        $check = Check::findOrFail($id);
        
        if (!$check->file_path || !Storage::disk('local')->exists($check->file_path)) {
            return response()->json(['error' => 'Файл не найден'], 404);
        }

        $path = Storage::disk('local')->path($check->file_path);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mimeType,
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Получить проблемные чеки для анализа (публичный API для оптимизации)
     * GET /api/checks/problems
     * 
     * Возвращает чеки со статусом 'failed' или 'partial',
     * а также чеки без суммы или без даты
     */
    public function problems(Request $request): JsonResponse
    {
        $query = Check::query()
            ->where(function ($q) {
                $q->where('status', 'failed')
                  ->orWhere('status', 'partial')
                  ->orWhereNull('amount')
                  ->orWhere('amount_found', false)
                  ->orWhere('date_found', false);
            })
            ->orderBy('created_at', 'desc');

        // Опциональный лимит
        $limit = $request->get('limit', 50);
        
        $checks = $query->take($limit)->get()->map(function ($check) {
            return [
                'id' => $check->id,
                'created_at' => $check->created_at->format('Y-m-d H:i:s'),
                'status' => $check->status,
                'file_type' => $check->file_type,
                'username' => $check->username,
                'first_name' => $check->first_name,
                'chat_id' => $check->chat_id,
                
                // Результаты распознавания
                'amount' => $check->amount,
                'amount_found' => $check->amount_found,
                'check_date' => $check->check_date,
                'date_found' => $check->date_found,
                
                // OCR информация для анализа
                'ocr_method' => $check->ocr_method,
                'text_length' => $check->text_length,
                'readable_ratio' => $check->readable_ratio,
                'raw_text' => $check->raw_text,
                
                // Проблемы
                'problems' => $this->identifyProblems($check),
                
                // Ссылки для анализа
                'detail_url' => url("/api/checks/analyze/{$check->id}"),
                'admin_url' => url("/checks/{$check->id}"),
            ];
        });

        return response()->json([
            'total_problems' => $checks->count(),
            'summary' => [
                'failed' => $checks->where('status', 'failed')->count(),
                'partial' => $checks->where('status', 'partial')->count(),
                'no_amount' => $checks->where('amount_found', false)->count(),
                'no_date' => $checks->where('date_found', false)->count(),
            ],
            'checks' => $checks,
        ]);
    }

    /**
     * Детальный анализ конкретного чека (публичный API)
     * GET /api/checks/analyze/{id}
     */
    public function analyze(int $id): JsonResponse
    {
        $check = Check::findOrFail($id);
        
        $analysis = [
            'id' => $check->id,
            'created_at' => $check->created_at->format('Y-m-d H:i:s'),
            'status' => $check->status,
            
            // Информация о файле
            'file' => [
                'type' => $check->file_type,
                'size' => $check->file_size,
                'path' => $check->file_path,
                'exists' => $check->file_path && Storage::disk('local')->exists($check->file_path),
            ],
            
            // Отправитель
            'sender' => [
                'username' => $check->username,
                'first_name' => $check->first_name,
                'chat_id' => $check->chat_id,
            ],
            
            // Результаты распознавания
            'recognition' => [
                'amount' => $check->amount,
                'amount_found' => $check->amount_found,
                'corrected_amount' => $check->corrected_amount,
                'check_date' => $check->check_date,
                'date_found' => $check->date_found,
                'corrected_date' => $check->corrected_date,
                'currency' => $check->currency,
            ],
            
            // OCR информация
            'ocr' => [
                'method' => $check->ocr_method,
                'text_length' => $check->text_length,
                'readable_ratio' => $check->readable_ratio,
                'raw_text' => $check->raw_text,
            ],
            
            // Выявленные проблемы
            'problems' => $this->identifyProblems($check),
            
            // Рекомендации по исправлению
            'recommendations' => $this->getRecommendations($check),
            
            // Заметки администратора
            'admin_notes' => $check->admin_notes,
        ];

        return response()->json($analysis);
    }

    /**
     * Идентифицировать проблемы с чеком
     */
    private function identifyProblems(Check $check): array
    {
        $problems = [];

        if ($check->status === 'failed') {
            $problems[] = 'OCR полностью не справился с распознаванием';
        }

        if (!$check->amount_found || $check->amount === null) {
            $problems[] = 'Сумма не найдена';
        }

        if (!$check->date_found || $check->check_date === null) {
            $problems[] = 'Дата не найдена';
        }

        if ($check->text_length !== null && $check->text_length < 50) {
            $problems[] = 'Очень мало распознанного текста (' . $check->text_length . ' символов)';
        }

        if ($check->readable_ratio !== null && $check->readable_ratio < 0.3) {
            $problems[] = 'Низкое качество распознавания (' . round($check->readable_ratio * 100) . '% читаемых символов)';
        }

        if (empty($check->raw_text)) {
            $problems[] = 'Текст вообще не был распознан';
        }

        if ($check->ocr_method === null) {
            $problems[] = 'Ни один OCR метод не сработал';
        }

        // Анализ raw_text на типичные проблемы
        if ($check->raw_text) {
            $text = $check->raw_text;
            
            // Проверка на "мусорный" текст
            if (preg_match('/[^\p{Cyrillic}\p{Latin}\d\s.,;:!?₽\-\/\\\\()%]+/u', $text, $matches)) {
                $garbageRatio = mb_strlen(implode('', $matches)) / mb_strlen($text);
                if ($garbageRatio > 0.3) {
                    $problems[] = 'Много нераспознаваемых символов (возможно плохое качество изображения)';
                }
            }
            
            // Проверка на наличие ключевых слов чека
            $hasKeywords = preg_match('/итого|сумма|всего|total|amount|чек|receipt/ui', $text);
            if (!$hasKeywords && $check->text_length > 100) {
                $problems[] = 'Текст распознан, но не содержит ключевых слов чека (возможно не чек)';
            }
        }

        return $problems;
    }

    /**
     * Получить рекомендации по исправлению
     */
    private function getRecommendations(Check $check): array
    {
        $recommendations = [];

        if ($check->text_length !== null && $check->text_length < 50) {
            $recommendations[] = 'Попросить пользователя отправить фото лучшего качества';
            $recommendations[] = 'Проверить настройки DPI при конвертации PDF';
        }

        if ($check->readable_ratio !== null && $check->readable_ratio < 0.4) {
            $recommendations[] = 'Улучшить предобработку изображения (контраст, резкость)';
            $recommendations[] = 'Попробовать другой OCR метод';
        }

        if (!$check->amount_found && $check->raw_text) {
            $recommendations[] = 'Проверить паттерны поиска суммы в parsePaymentAmount()';
            $recommendations[] = 'Возможно формат суммы нестандартный - добавить новый паттерн';
        }

        if (!$check->date_found && $check->raw_text) {
            $recommendations[] = 'Проверить паттерны поиска даты';
            $recommendations[] = 'Дата может быть в нестандартном формате или плохо видна';
        }

        if ($check->file_type === 'pdf') {
            $recommendations[] = 'PDF: проверить разрешение конвертации (сейчас 450 DPI)';
            $recommendations[] = 'PDF: возможно нужна другая предобработка';
        }

        return $recommendations;
    }
}
