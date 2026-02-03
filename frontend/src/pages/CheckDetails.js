import React, { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { TransformWrapper, TransformComponent } from 'react-zoom-pan-pinch';

// Отключаем react-pdf из-за проблем с worker - используем iframe для PDF

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function CheckDetails() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [check, setCheck] = useState(null);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(false);
  const [formData, setFormData] = useState({
    corrected_amount: '',
    corrected_date: '',
    admin_notes: '',
  });
  const [saving, setSaving] = useState(false);
  const [fileUrl, setFileUrl] = useState(null);

  const fetchCheck = useCallback(async () => {
    setLoading(true);
    const token = localStorage.getItem('token');

    try {
      const response = await fetch(`${API_URL}/api/checks/${id}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setCheck(data);
        setFormData({
          corrected_amount: data.corrected_amount || '',
          corrected_date: data.corrected_date ? data.corrected_date.slice(0, 16) : '',
          admin_notes: data.admin_notes || '',
        });
        
        // Создаем URL для файла
        if (data.file_path) {
          setFileUrl(`${API_URL}/api/checks/${id}/file?token=${token}`);
        }
      } else if (response.status === 404) {
        navigate('/checks');
      }
    } catch (error) {
      console.error('Error fetching check:', error);
    } finally {
      setLoading(false);
    }
  }, [id, navigate]);

  useEffect(() => {
    fetchCheck();
  }, [fetchCheck]);

  const handleSave = async () => {
    setSaving(true);
    const token = localStorage.getItem('token');

    try {
      const response = await fetch(`${API_URL}/api/checks/${id}`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          corrected_amount: formData.corrected_amount || null,
          corrected_date: formData.corrected_date || null,
          admin_notes: formData.admin_notes || null,
        }),
      });

      if (response.ok) {
        const data = await response.json();
        setCheck(data.check);
        setEditing(false);
      }
    } catch (error) {
      console.error('Error saving check:', error);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!window.confirm('Удалить этот чек?')) return;

    const token = localStorage.getItem('token');
    try {
      const response = await fetch(`${API_URL}/api/checks/${id}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        navigate('/checks');
      }
    } catch (error) {
      console.error('Error deleting check:', error);
    }
  };

  const formatDate = (dateString) => {
    if (!dateString) return '—';
    const date = new Date(dateString);
    return date.toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const formatAmount = (amount) => {
    if (!amount) return '—';
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency: 'RUB',
    }).format(amount);
  };

  const getStatusBadge = (status) => {
    const badges = {
      success: 'bg-green-100 text-green-800 border-green-200',
      partial: 'bg-yellow-100 text-yellow-800 border-yellow-200',
      failed: 'bg-red-100 text-red-800 border-red-200',
    };
    const labels = {
      success: '✅ Успешно распознан',
      partial: '⚠️ Частично распознан',
      failed: '❌ Ошибка распознавания',
    };
    return (
      <span className={`px-3 py-1 rounded-full text-sm font-medium border ${badges[status] || badges.failed}`}>
        {labels[status] || status}
      </span>
    );
  };

  const getOcrMethodLabel = (method) => {
    const labels = {
      extractTextWithTesseract: 'Tesseract (локальный)',
      extractTextWithRemoteTesseract: 'Tesseract (VPS)',
      extractTextWithOCRspace: 'OCR.space',
      extractTextWithGoogleVision: 'Google Vision',
    };
    return labels[method] || method || '—';
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  if (!check) {
    return (
      <div className="p-6">
        <div className="text-center text-gray-500">Чек не найден</div>
      </div>
    );
  }

  const isPdf = check.file_type === 'pdf';
  const token = localStorage.getItem('token');

  return (
    <div className="p-6 max-w-7xl mx-auto">
      {/* Header */}
      <div className="mb-6 flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/checks"
            className="text-gray-500 hover:text-gray-700 transition"
          >
            ← Назад к списку
          </Link>
          <h1 className="text-2xl font-bold text-gray-800">Чек #{check.id}</h1>
          {getStatusBadge(check.status)}
        </div>
        <div className="flex gap-2">
          {!editing && (
            <button
              onClick={() => setEditing(true)}
              className="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition"
            >
              ✏️ Редактировать
            </button>
          )}
          <button
            onClick={handleDelete}
            className="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition"
          >
            🗑️ Удалить
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* File Viewer */}
        <div className="bg-white rounded-xl shadow-lg overflow-hidden">
          <div className="bg-gray-100 px-4 py-3 border-b flex items-center justify-between">
            <h2 className="font-semibold text-gray-700">
              📄 {isPdf ? 'PDF документ' : 'Изображение'}
            </h2>
            {check.file_path && (
              <a
                href={`${API_URL}/api/checks/${id}/file?token=${token}`}
                target="_blank"
                rel="noopener noreferrer"
                className="text-blue-600 hover:text-blue-800 text-sm"
              >
                ⬇️ Скачать
              </a>
            )}
          </div>
          
          <div className="p-4 bg-gray-50 min-h-[500px] flex items-center justify-center">
            {check.file_path ? (
              isPdf ? (
                <div className="w-full h-[600px] flex flex-col">
                  <iframe
                    src={`${API_URL}/api/checks/${id}/file?token=${token}`}
                    className="w-full flex-1 border-0 rounded"
                    title="PDF Preview"
                  />
                  <div className="text-center mt-3">
                    <a
                      href={`${API_URL}/api/checks/${id}/file?token=${token}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-blue-600 hover:underline text-sm"
                    >
                      📄 Открыть PDF в новой вкладке
                    </a>
                  </div>
                </div>
              ) : (
                <TransformWrapper
                  initialScale={1}
                  minScale={0.5}
                  maxScale={4}
                >
                  {({ zoomIn, zoomOut, resetTransform }) => (
                    <div className="w-full">
                      <div className="flex justify-center gap-2 mb-3">
                        <button
                          onClick={() => zoomIn()}
                          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 text-sm"
                        >
                          🔍+
                        </button>
                        <button
                          onClick={() => zoomOut()}
                          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 text-sm"
                        >
                          🔍-
                        </button>
                        <button
                          onClick={() => resetTransform()}
                          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 text-sm"
                        >
                          ↺ Сброс
                        </button>
                      </div>
                      <TransformComponent wrapperClass="w-full" contentClass="w-full flex justify-center">
                        <img
                          src={`${API_URL}/api/checks/${id}/file?token=${token}`}
                          alt="Чек"
                          className="max-w-full max-h-[600px] object-contain rounded shadow"
                          onError={(e) => {
                            e.target.style.display = 'none';
                            e.target.nextSibling.style.display = 'block';
                          }}
                        />
                        <div className="hidden text-center p-8 text-red-500">
                          Не удалось загрузить изображение
                        </div>
                      </TransformComponent>
                    </div>
                  )}
                </TransformWrapper>
              )
            ) : (
              <div className="text-gray-400 text-center">
                <div className="text-6xl mb-2">📄</div>
                Файл не сохранен
              </div>
            )}
          </div>
        </div>

        {/* Details */}
        <div className="space-y-6">
          {/* Main Info */}
          <div className="bg-white rounded-xl shadow-lg p-6">
            <h2 className="font-semibold text-gray-700 mb-4 text-lg">💰 Данные чека</h2>
            
            {editing ? (
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-600 mb-1">
                    Сумма (коррекция)
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    value={formData.corrected_amount}
                    onChange={(e) => setFormData(prev => ({ ...prev, corrected_amount: e.target.value }))}
                    placeholder={check.amount ? `Распознано: ${check.amount}` : 'Введите сумму'}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-600 mb-1">
                    Дата чека (коррекция)
                  </label>
                  <input
                    type="datetime-local"
                    value={formData.corrected_date}
                    onChange={(e) => setFormData(prev => ({ ...prev, corrected_date: e.target.value }))}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-600 mb-1">
                    Заметки
                  </label>
                  <textarea
                    value={formData.admin_notes}
                    onChange={(e) => setFormData(prev => ({ ...prev, admin_notes: e.target.value }))}
                    rows={3}
                    placeholder="Комментарий администратора..."
                    className="w-full border border-gray-300 rounded-lg px-3 py-2"
                  />
                </div>
                <div className="flex gap-2">
                  <button
                    onClick={handleSave}
                    disabled={saving}
                    className="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition disabled:opacity-50"
                  >
                    {saving ? '💾 Сохранение...' : '💾 Сохранить'}
                  </button>
                  <button
                    onClick={() => setEditing(false)}
                    className="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300 transition"
                  >
                    Отмена
                  </button>
                </div>
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <div className="text-sm text-gray-500">Сумма</div>
                  <div className="text-2xl font-bold text-gray-900">
                    {check.corrected_amount ? (
                      <span className="text-orange-600">{formatAmount(check.corrected_amount)}</span>
                    ) : (
                      formatAmount(check.amount)
                    )}
                  </div>
                  {check.corrected_amount && check.amount && (
                    <div className="text-xs text-gray-400 line-through">
                      Было: {formatAmount(check.amount)}
                    </div>
                  )}
                </div>
                <div>
                  <div className="text-sm text-gray-500">Дата чека</div>
                  <div className="text-lg font-medium text-gray-900">
                    {check.corrected_date ? (
                      <span className="text-orange-600">{formatDate(check.corrected_date)}</span>
                    ) : (
                      formatDate(check.check_date)
                    )}
                  </div>
                  {check.corrected_date && check.check_date && (
                    <div className="text-xs text-gray-400 line-through">
                      Было: {formatDate(check.check_date)}
                    </div>
                  )}
                </div>
                <div>
                  <div className="text-sm text-gray-500">Валюта</div>
                  <div className="text-gray-900">{check.currency || 'RUB'}</div>
                </div>
                <div>
                  <div className="text-sm text-gray-500">Тип файла</div>
                  <div className="text-gray-900">{check.file_type?.toUpperCase() || 'N/A'}</div>
                </div>
              </div>
            )}

            {check.admin_notes && !editing && (
              <div className="mt-4 p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                <div className="text-sm font-medium text-yellow-800">📝 Заметка:</div>
                <div className="text-yellow-700">{check.admin_notes}</div>
              </div>
            )}
          </div>

          {/* User Info */}
          <div className="bg-white rounded-xl shadow-lg p-6">
            <h2 className="font-semibold text-gray-700 mb-4 text-lg">👤 Отправитель</h2>
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-blue-500 rounded-full flex items-center justify-center text-white text-xl font-bold">
                {(check.first_name || check.username || 'U').charAt(0).toUpperCase()}
              </div>
              <div>
                <div className="font-medium text-gray-900">{check.first_name || '—'}</div>
                <div className="text-gray-500 text-sm">
                  @{check.username || `ID: ${check.chat_id}`}
                </div>
              </div>
            </div>
          </div>

          {/* OCR Info */}
          <div className="bg-white rounded-xl shadow-lg p-6">
            <h2 className="font-semibold text-gray-700 mb-4 text-lg">🤖 OCR информация</h2>
            <div className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <div className="text-gray-500">Метод</div>
                <div className="text-gray-900 font-medium">{getOcrMethodLabel(check.ocr_method)}</div>
              </div>
              <div>
                <div className="text-gray-500">Длина текста</div>
                <div className="text-gray-900">{check.text_length || '—'} символов</div>
              </div>
              <div>
                <div className="text-gray-500">Качество</div>
                <div className="text-gray-900">
                  {check.readable_ratio ? `${Math.round(check.readable_ratio * 100)}%` : '—'}
                </div>
              </div>
              <div>
                <div className="text-gray-500">Создан</div>
                <div className="text-gray-900">{formatDate(check.created_at)}</div>
              </div>
            </div>
          </div>

          {/* Raw Text */}
          {check.raw_text && (
            <div className="bg-white rounded-xl shadow-lg p-6">
              <h2 className="font-semibold text-gray-700 mb-4 text-lg">📝 Распознанный текст</h2>
              <pre className="bg-gray-100 p-4 rounded-lg text-sm overflow-x-auto whitespace-pre-wrap max-h-60 text-gray-700">
                {check.raw_text}
              </pre>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

export default CheckDetails;
