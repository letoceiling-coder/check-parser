import React, { useState, useEffect, useCallback } from 'react';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function Checks() {
  const [checks, setChecks] = useState([]);
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [filters, setFilters] = useState({
    status: 'all',
    ocr_method: 'all',
    search: '',
  });
  const [selectedCheck, setSelectedCheck] = useState(null);
  const [showModal, setShowModal] = useState(false);

  const fetchChecks = useCallback(async (page = 1) => {
    setLoading(true);
    const token = localStorage.getItem('token');
    
    try {
      const params = new URLSearchParams({
        page: page.toString(),
        per_page: '20',
        ...Object.fromEntries(
          Object.entries(filters).filter(([_, v]) => v && v !== 'all')
        ),
      });

      const response = await fetch(`${API_URL}/api/checks?${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setChecks(data.data || []);
        setCurrentPage(data.current_page || 1);
        setLastPage(data.last_page || 1);
        setTotal(data.total || 0);
      }
    } catch (error) {
      console.error('Error fetching checks:', error);
    } finally {
      setLoading(false);
    }
  }, [filters]);

  const fetchStats = useCallback(async () => {
    const token = localStorage.getItem('token');
    
    try {
      const response = await fetch(`${API_URL}/api/checks/stats`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setStats(data);
      }
    } catch (error) {
      console.error('Error fetching stats:', error);
    }
  }, []);

  useEffect(() => {
    fetchChecks(currentPage);
    fetchStats();
  }, [fetchChecks, fetchStats, currentPage]);

  const handleFilterChange = (key, value) => {
    setFilters(prev => ({ ...prev, [key]: value }));
    setCurrentPage(1);
  };

  const handleSearch = (e) => {
    e.preventDefault();
    fetchChecks(1);
  };

  const openCheckDetails = (check) => {
    setSelectedCheck(check);
    setShowModal(true);
  };

  const closeModal = () => {
    setShowModal(false);
    setSelectedCheck(null);
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
      success: 'bg-green-100 text-green-800',
      partial: 'bg-yellow-100 text-yellow-800',
      failed: 'bg-red-100 text-red-800',
    };
    const labels = {
      success: 'Успешно',
      partial: 'Частично',
      failed: 'Ошибка',
    };
    return (
      <span className={`px-2 py-1 rounded-full text-xs font-medium ${badges[status] || badges.failed}`}>
        {labels[status] || status}
      </span>
    );
  };

  const getOcrMethodLabel = (method) => {
    const labels = {
      extractTextWithTesseract: 'Tesseract (local)',
      extractTextWithRemoteTesseract: 'Tesseract (VPS)',
      extractTextWithOCRspace: 'OCR.space',
      extractTextWithGoogleVision: 'Google Vision',
    };
    return labels[method] || method || '—';
  };

  return (
    <div className="p-6">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800">📝 Чеки</h1>
        <p className="text-gray-600">История обработанных чеков и статистика</p>
      </div>

      {/* Stats Cards */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
          <div className="bg-white rounded-lg shadow p-4">
            <div className="text-3xl font-bold text-gray-800">{stats.total}</div>
            <div className="text-gray-600 text-sm">Всего чеков</div>
          </div>
          <div className="bg-white rounded-lg shadow p-4">
            <div className="text-3xl font-bold text-green-600">{stats.success}</div>
            <div className="text-gray-600 text-sm">Успешно ({stats.success_rate}%)</div>
          </div>
          <div className="bg-white rounded-lg shadow p-4">
            <div className="text-3xl font-bold text-yellow-600">{stats.partial}</div>
            <div className="text-gray-600 text-sm">Частично распознано</div>
          </div>
          <div className="bg-white rounded-lg shadow p-4">
            <div className="text-3xl font-bold text-red-600">{stats.failed}</div>
            <div className="text-gray-600 text-sm">Ошибки распознавания</div>
          </div>
        </div>
      )}

      {/* Filters */}
      <div className="bg-white rounded-lg shadow p-4 mb-6">
        <form onSubmit={handleSearch} className="flex flex-wrap gap-4 items-end">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Статус</label>
            <select
              value={filters.status}
              onChange={(e) => handleFilterChange('status', e.target.value)}
              className="border border-gray-300 rounded-lg px-3 py-2"
            >
              <option value="all">Все</option>
              <option value="success">Успешно</option>
              <option value="partial">Частично</option>
              <option value="failed">Ошибка</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">OCR метод</label>
            <select
              value={filters.ocr_method}
              onChange={(e) => handleFilterChange('ocr_method', e.target.value)}
              className="border border-gray-300 rounded-lg px-3 py-2"
            >
              <option value="all">Все</option>
              <option value="extractTextWithTesseract">Tesseract (local)</option>
              <option value="extractTextWithRemoteTesseract">Tesseract (VPS)</option>
              <option value="extractTextWithOCRspace">OCR.space</option>
              <option value="extractTextWithGoogleVision">Google Vision</option>
            </select>
          </div>
          <div className="flex-1">
            <label className="block text-sm font-medium text-gray-700 mb-1">Поиск</label>
            <input
              type="text"
              value={filters.search}
              onChange={(e) => setFilters(prev => ({ ...prev, search: e.target.value }))}
              placeholder="Имя пользователя или chat_id..."
              className="w-full border border-gray-300 rounded-lg px-3 py-2"
            />
          </div>
          <button
            type="submit"
            className="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition"
          >
            🔍 Поиск
          </button>
        </form>
      </div>

      {/* Table */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дата</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Пользователь</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Файл</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Сумма</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дата чека</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">OCR</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Действия</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {loading ? (
                <tr>
                  <td colSpan="9" className="px-4 py-8 text-center text-gray-500">
                    <div className="flex justify-center">
                      <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
                    </div>
                  </td>
                </tr>
              ) : checks.length === 0 ? (
                <tr>
                  <td colSpan="9" className="px-4 py-8 text-center text-gray-500">
                    Нет данных
                  </td>
                </tr>
              ) : (
                checks.map((check) => (
                  <tr key={check.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm text-gray-900">{check.id}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{formatDate(check.created_at)}</td>
                    <td className="px-4 py-3 text-sm">
                      <div className="font-medium text-gray-900">{check.first_name || '—'}</div>
                      <div className="text-gray-500 text-xs">@{check.username || check.chat_id}</div>
                    </td>
                    <td className="px-4 py-3 text-sm">
                      <span className={`px-2 py-1 rounded text-xs ${
                        check.file_type === 'pdf' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'
                      }`}>
                        {check.file_type?.toUpperCase() || 'IMG'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm font-medium text-gray-900">
                      {check.corrected_amount ? (
                        <span className="text-orange-600">{formatAmount(check.corrected_amount)}</span>
                      ) : (
                        formatAmount(check.amount)
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {check.corrected_date ? (
                        <span className="text-orange-600">{formatDate(check.corrected_date)}</span>
                      ) : (
                        formatDate(check.check_date)
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {getOcrMethodLabel(check.ocr_method)}
                    </td>
                    <td className="px-4 py-3">{getStatusBadge(check.status)}</td>
                    <td className="px-4 py-3">
                      <button
                        onClick={() => openCheckDetails(check)}
                        className="text-blue-600 hover:text-blue-800 text-sm font-medium"
                      >
                        Подробнее
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {lastPage > 1 && (
          <div className="px-4 py-3 border-t border-gray-200 flex items-center justify-between">
            <div className="text-sm text-gray-600">
              Показано {checks.length} из {total}
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
                disabled={currentPage === 1}
                className="px-3 py-1 border rounded text-sm disabled:opacity-50 disabled:cursor-not-allowed"
              >
                ← Назад
              </button>
              <span className="px-3 py-1 text-sm">
                {currentPage} / {lastPage}
              </span>
              <button
                onClick={() => setCurrentPage(prev => Math.min(prev + 1, lastPage))}
                disabled={currentPage === lastPage}
                className="px-3 py-1 border rounded text-sm disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Вперед →
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Modal */}
      {showModal && selectedCheck && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div className="p-6">
              <div className="flex justify-between items-start mb-4">
                <h2 className="text-xl font-bold">Чек #{selectedCheck.id}</h2>
                <button
                  onClick={closeModal}
                  className="text-gray-500 hover:text-gray-700"
                >
                  ✕
                </button>
              </div>

              <div className="grid grid-cols-2 gap-4 mb-4">
                <div>
                  <label className="text-sm font-medium text-gray-500">Пользователь</label>
                  <p className="text-gray-900">
                    {selectedCheck.first_name || '—'} (@{selectedCheck.username || selectedCheck.chat_id})
                  </p>
                </div>
                <div>
                  <label className="text-sm font-medium text-gray-500">Статус</label>
                  <p>{getStatusBadge(selectedCheck.status)}</p>
                </div>
                <div>
                  <label className="text-sm font-medium text-gray-500">Сумма</label>
                  <p className="text-lg font-bold text-gray-900">
                    {formatAmount(selectedCheck.corrected_amount || selectedCheck.amount)}
                  </p>
                </div>
                <div>
                  <label className="text-sm font-medium text-gray-500">Дата чека</label>
                  <p className="text-gray-900">
                    {formatDate(selectedCheck.corrected_date || selectedCheck.check_date)}
                  </p>
                </div>
                <div>
                  <label className="text-sm font-medium text-gray-500">OCR метод</label>
                  <p className="text-gray-900">{getOcrMethodLabel(selectedCheck.ocr_method)}</p>
                </div>
                <div>
                  <label className="text-sm font-medium text-gray-500">Качество распознавания</label>
                  <p className="text-gray-900">
                    {selectedCheck.text_length ? `${selectedCheck.text_length} симв.` : '—'}
                    {selectedCheck.readable_ratio ? ` (${Math.round(selectedCheck.readable_ratio * 100)}% читаемых)` : ''}
                  </p>
                </div>
              </div>

              {selectedCheck.raw_text && (
                <div className="mb-4">
                  <label className="text-sm font-medium text-gray-500">Распознанный текст</label>
                  <pre className="mt-1 p-3 bg-gray-100 rounded text-sm overflow-x-auto whitespace-pre-wrap max-h-40">
                    {selectedCheck.raw_text}
                  </pre>
                </div>
              )}

              {selectedCheck.file_path && (
                <div className="mb-4">
                  <label className="text-sm font-medium text-gray-500">Файл</label>
                  <div className="mt-1">
                    <a
                      href={`${API_URL}/api/checks/${selectedCheck.id}/file`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center text-blue-600 hover:text-blue-800"
                    >
                      📎 Открыть файл ({selectedCheck.file_type?.toUpperCase()})
                    </a>
                  </div>
                </div>
              )}

              {selectedCheck.admin_notes && (
                <div className="mb-4">
                  <label className="text-sm font-medium text-gray-500">Заметки</label>
                  <p className="mt-1 text-gray-700">{selectedCheck.admin_notes}</p>
                </div>
              )}

              <div className="border-t pt-4 flex justify-end">
                <button
                  onClick={closeModal}
                  className="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300 transition"
                >
                  Закрыть
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default Checks;
