import React, { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import CompleteRaffleModal from '../components/CompleteRaffleModal';
import NewRaffleModal from '../components/NewRaffleModal';

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
  
  // Raffle state
  const [botId, setBotId] = useState(null);
  const [currentRaffle, setCurrentRaffle] = useState(null);
  const [showCompleteModal, setShowCompleteModal] = useState(false);
  const [showNewRaffleModal, setShowNewRaffleModal] = useState(false);
  const [raffleSuccess, setRaffleSuccess] = useState(null);
  const [reparseFailedLoading, setReparseFailedLoading] = useState(false);
  const [reparseFailedMessage, setReparseFailedMessage] = useState(null);

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

  const fetchBot = useCallback(async () => {
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const bots = await response.json();
        if (bots.length > 0) {
          setBotId(bots[0].id);
        }
      }
    } catch (err) {
      console.error('Error fetching bot:', err);
    }
  }, []);

  const fetchCurrentRaffle = useCallback(async () => {
    if (!botId) return;
    
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${botId}/raffles/current`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setCurrentRaffle(data.raffle);
      }
    } catch (err) {
      console.error('Error fetching current raffle:', err);
    }
  }, [botId]);

  useEffect(() => {
    fetchBot();
  }, [fetchBot]);

  useEffect(() => {
    if (botId) {
      fetchCurrentRaffle();
    }
  }, [botId, fetchCurrentRaffle]);

  useEffect(() => {
    fetchChecks(currentPage);
    fetchStats();
  }, [fetchChecks, fetchStats, currentPage]);

  const handleRaffleComplete = (data) => {
    setRaffleSuccess(`🏆 Розыгрыш завершён! Победитель: №${data.winner.ticket_number}`);
    setCurrentRaffle(null);
    fetchCurrentRaffle();
    setTimeout(() => setRaffleSuccess(null), 5000);
  };

  const handleNewRaffle = (data) => {
    setRaffleSuccess(`🎯 Новый розыгрыш "${data.raffle.name}" начат!`);
    setCurrentRaffle(data.raffle);
    setTimeout(() => setRaffleSuccess(null), 5000);
  };

  const handleFilterChange = (key, value) => {
    setFilters(prev => ({ ...prev, [key]: value }));
    setCurrentPage(1);
  };

  const handleSearch = (e) => {
    e.preventDefault();
    fetchChecks(1);
  };

  const handleReparseFailed = async () => {
    setReparseFailedMessage(null);
    setReparseFailedLoading(true);
    const token = localStorage.getItem('token');
    try {
      const response = await fetch(`${API_URL}/api/checks/reparse-failed`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      if (response.ok && data.success) {
        setReparseFailedMessage(data.message || `Обработано: ${data.processed}, успешно: ${data.success_count}`);
        fetchChecks(currentPage);
        fetchStats();
        setTimeout(() => setReparseFailedMessage(null), 8000);
      } else {
        setReparseFailedMessage(data.message || 'Ошибка при переобработке');
      }
    } catch (err) {
      setReparseFailedMessage('Ошибка сети: ' + err.message);
    } finally {
      setReparseFailedLoading(false);
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
      pdftotext: 'PDF (текст)',
      extractTextWithTesseract: 'Tesseract',
      extractTextWithRemoteTesseract: 'VPS',
      extractTextWithOCRspace: 'OCR.space',
      extractTextWithGoogleVision: 'Google',
    };
    return labels[method] || method || '—';
  };

  return (
    <div className="p-6">
      {/* Modals */}
      <CompleteRaffleModal
        isOpen={showCompleteModal}
        onClose={() => setShowCompleteModal(false)}
        botId={botId}
        onComplete={handleRaffleComplete}
      />
      <NewRaffleModal
        isOpen={showNewRaffleModal}
        onClose={() => setShowNewRaffleModal(false)}
        botId={botId}
        onCreated={handleNewRaffle}
      />

      {/* Header */}
      <div className="mb-6 flex justify-between items-start">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">🧾 Чеки</h1>
          <p className="text-gray-600">История обработанных чеков и статистика</p>
        </div>
        
        {/* Raffle Controls */}
        {botId && (
          <div className="flex gap-3">
            <Link
              to="/raffles"
              className="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium"
            >
              📜 История розыгрышей
            </Link>
            <button
              onClick={() => setShowCompleteModal(true)}
              className="px-4 py-2 bg-gradient-to-r from-purple-500 to-indigo-600 text-white rounded-lg hover:from-purple-600 hover:to-indigo-700 transition-colors font-medium shadow-md"
            >
              🏆 Завершить розыгрыш
            </button>
            <button
              onClick={() => setShowNewRaffleModal(true)}
              className="px-4 py-2 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-lg hover:from-green-600 hover:to-emerald-700 transition-colors font-medium shadow-md"
            >
              🔄 Новый розыгрыш
            </button>
          </div>
        )}
      </div>

      {/* Success Message */}
      {raffleSuccess && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center gap-3">
          <span className="text-2xl">✅</span>
          <span>{raffleSuccess}</span>
        </div>
      )}

      {/* Current Raffle Info */}
      {currentRaffle && (
        <div className="bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-200 rounded-xl p-4 mb-6">
          <div className="flex justify-between items-center">
            <div>
              <h3 className="font-semibold text-purple-800">{currentRaffle.name}</h3>
              <p className="text-sm text-purple-600">
                Участников: {currentRaffle.total_participants || 0} • 
                Номерков: {currentRaffle.tickets_issued || 0} / {currentRaffle.total_slots}
              </p>
            </div>
            <span className="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium">
              🟢 Активен
            </span>
          </div>
        </div>
      )}

      {/* Stats Cards */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
          <div className="bg-white rounded-xl shadow-lg p-5 border-l-4 border-gray-400">
            <div className="text-3xl font-bold text-gray-800">{stats.total}</div>
            <div className="text-gray-600 text-sm">Всего чеков</div>
          </div>
          <div className="bg-white rounded-xl shadow-lg p-5 border-l-4 border-green-500">
            <div className="text-3xl font-bold text-green-600">{stats.success}</div>
            <div className="text-gray-600 text-sm">Успешно ({stats.success_rate}%)</div>
          </div>
          <div className="bg-white rounded-xl shadow-lg p-5 border-l-4 border-yellow-500">
            <div className="text-3xl font-bold text-yellow-600">{stats.partial}</div>
            <div className="text-gray-600 text-sm">Частично</div>
          </div>
          <div className="bg-white rounded-xl shadow-lg p-5 border-l-4 border-red-500 flex flex-col">
            <div className="text-3xl font-bold text-red-600">{stats.failed}</div>
            <div className="text-gray-600 text-sm">Ошибки</div>
            {stats.failed > 0 && (
              <button
                type="button"
                onClick={handleReparseFailed}
                disabled={reparseFailedLoading}
                className="mt-3 w-full px-3 py-2 bg-red-50 text-red-700 border border-red-200 rounded-lg text-sm font-medium hover:bg-red-100 disabled:opacity-50 transition"
              >
                {reparseFailedLoading ? '⏳ Переобработка...' : '🔄 Переобработать чеки с ошибкой'}
              </button>
            )}
          </div>
        </div>
      )}

      {reparseFailedMessage && (
        <div className="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg mb-6">
          {reparseFailedMessage}
        </div>
      )}

      {/* Filters */}
      <div className="bg-white rounded-xl shadow-lg p-4 mb-6">
        <form onSubmit={handleSearch} className="flex flex-wrap gap-4 items-end">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Статус</label>
            <select
              value={filters.status}
              onChange={(e) => handleFilterChange('status', e.target.value)}
              className="border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            >
              <option value="all">Все</option>
              <option value="success">Успешно</option>
              <option value="partial">Частично</option>
              <option value="failed">Ошибка</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">OCR</label>
            <select
              value={filters.ocr_method}
              onChange={(e) => handleFilterChange('ocr_method', e.target.value)}
              className="border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            >
              <option value="all">Все</option>
              <option value="pdftotext">PDF (текст)</option>
              <option value="extractTextWithTesseract">Tesseract</option>
              <option value="extractTextWithRemoteTesseract">VPS Tesseract</option>
              <option value="extractTextWithOCRspace">OCR.space</option>
              <option value="extractTextWithGoogleVision">Google Vision</option>
            </select>
          </div>
          <div className="flex-1 min-w-[200px]">
            <label className="block text-sm font-medium text-gray-700 mb-1">Поиск</label>
            <input
              type="text"
              value={filters.search}
              onChange={(e) => setFilters(prev => ({ ...prev, search: e.target.value }))}
              placeholder="Имя или chat_id..."
              className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            />
          </div>
          <button
            type="submit"
            className="bg-blue-500 text-white px-5 py-2 rounded-lg hover:bg-blue-600 transition shadow-md"
          >
            🔍 Поиск
          </button>
        </form>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl shadow-lg overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">ID</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Создан</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Пользователь</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Тип</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Сумма</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Дата чека</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">OCR</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Статус</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase"></th>
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
                  <td colSpan="9" className="px-4 py-12 text-center text-gray-400">
                    <div className="text-5xl mb-2">📭</div>
                    Нет данных
                  </td>
                </tr>
              ) : (
                checks.map((check) => (
                  <tr key={check.id} className="hover:bg-blue-50 transition-colors">
                    <td className="px-4 py-3 text-sm font-medium text-gray-900">#{check.id}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{formatDate(check.created_at)}</td>
                    <td className="px-4 py-3 text-sm">
                      <div className="font-medium text-gray-900">{check.first_name || '—'}</div>
                      <div className="text-gray-500 text-xs">@{check.username || check.chat_id}</div>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-1 rounded text-xs font-medium ${
                        check.file_type === 'pdf' 
                          ? 'bg-red-100 text-red-700' 
                          : 'bg-blue-100 text-blue-700'
                      }`}>
                        {check.file_type === 'pdf' ? '📄 PDF' : '🖼️ IMG'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm font-semibold">
                      {check.corrected_amount ? (
                        <span className="text-orange-600">{formatAmount(check.corrected_amount)}</span>
                      ) : (
                        <span className="text-gray-900">{formatAmount(check.amount)}</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {check.corrected_date ? (
                        <span className="text-orange-600">{formatDate(check.corrected_date)}</span>
                      ) : (
                        formatDate(check.check_date)
                      )}
                    </td>
                    <td className="px-4 py-3 text-xs text-gray-500">
                      {getOcrMethodLabel(check.ocr_method)}
                    </td>
                    <td className="px-4 py-3">
                      <span className="flex flex-wrap items-center gap-2">
                        {getStatusBadge(check.status)}
                        {check.needs_review && (
                          <span className="px-2 py-1 rounded text-xs font-medium bg-amber-100 text-amber-800 border border-amber-200">
                            Требует проверки
                          </span>
                        )}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <Link
                        to={`/checks/${check.id}`}
                        className="inline-flex items-center text-blue-600 hover:text-blue-800 text-sm font-medium transition"
                      >
                        Открыть →
                      </Link>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {lastPage > 1 && (
          <div className="px-4 py-3 border-t border-gray-200 flex items-center justify-between bg-gray-50">
            <div className="text-sm text-gray-600">
              Показано {checks.length} из {total}
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
                disabled={currentPage === 1}
                className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed transition"
              >
                ← Назад
              </button>
              <span className="px-4 py-2 text-sm font-medium">
                {currentPage} / {lastPage}
              </span>
              <button
                onClick={() => setCurrentPage(prev => Math.min(prev + 1, lastPage))}
                disabled={currentPage === lastPage}
                className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed transition"
              >
                Вперед →
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

export default Checks;
