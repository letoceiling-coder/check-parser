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
     */
    public function file(int $id)
    {
        $check = Check::findOrFail($id);
        
        if (!$check->file_path || !Storage::disk('local')->exists($check->file_path)) {
            return response()->json(['error' => 'Файл не найден'], 404);
        }

        $path = Storage::disk('local')->path($check->file_path);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mimeType,
        ]);
    }
}
