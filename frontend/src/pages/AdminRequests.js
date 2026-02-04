import React, { useState, useEffect } from 'react';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function AdminRequests() {
  const [requests, setRequests] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('pending');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [actionLoading, setActionLoading] = useState(null);

  useEffect(() => {
    fetchRequests();
  }, [page, filter]);

  const fetchRequests = async () => {
    try {
      const token = localStorage.getItem('token');
      const params = new URLSearchParams({
        page,
        per_page: 20,
        status: filter,
      });

      const response = await fetch(`${API_URL}/api/admin-requests?${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setRequests(data.data || []);
        setTotalPages(data.last_page || 1);
      }
    } catch (error) {
      console.error('Error fetching requests:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleApprove = async (id) => {
    if (!window.confirm('Одобрить запрос на роль администратора?')) return;

    setActionLoading(id);
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/admin-requests/${id}/approve`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({}),
      });

      if (response.ok) {
        fetchRequests();
      } else {
        const data = await response.json();
        alert(data.error || 'Ошибка при одобрении');
      }
    } catch (error) {
      console.error('Error approving request:', error);
      alert('Ошибка при одобрении');
    } finally {
      setActionLoading(null);
    }
  };

  const handleReject = async (id) => {
    const reason = window.prompt('Укажите причину отклонения (опционально):');
    if (reason === null) return; // Cancelled

    setActionLoading(id);
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/admin-requests/${id}/reject`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ comment: reason }),
      });

      if (response.ok) {
        fetchRequests();
      } else {
        const data = await response.json();
        alert(data.error || 'Ошибка при отклонении');
      }
    } catch (error) {
      console.error('Error rejecting request:', error);
      alert('Ошибка при отклонении');
    } finally {
      setActionLoading(null);
    }
  };

  const getStatusBadge = (status) => {
    switch (status) {
      case 'pending':
        return <span className="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">Ожидает</span>;
      case 'approved':
        return <span className="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">Одобрен</span>;
      case 'rejected':
        return <span className="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">Отклонён</span>;
      default:
        return null;
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <h1 className="text-3xl font-bold text-gray-800">👤 Запросы на роли</h1>

      {/* Фильтры */}
      <div className="bg-white p-4 rounded-lg shadow-md flex gap-4">
        <button
          onClick={() => { setFilter('pending'); setPage(1); }}
          className={`px-4 py-2 rounded-lg font-medium transition-colors ${
            filter === 'pending' 
              ? 'bg-yellow-500 text-white' 
              : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
          }`}
        >
          ⏳ Ожидающие
        </button>
        <button
          onClick={() => { setFilter('approved'); setPage(1); }}
          className={`px-4 py-2 rounded-lg font-medium transition-colors ${
            filter === 'approved' 
              ? 'bg-green-500 text-white' 
              : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
          }`}
        >
          ✅ Одобренные
        </button>
        <button
          onClick={() => { setFilter('rejected'); setPage(1); }}
          className={`px-4 py-2 rounded-lg font-medium transition-colors ${
            filter === 'rejected' 
              ? 'bg-red-500 text-white' 
              : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
          }`}
        >
          ❌ Отклонённые
        </button>
        <button
          onClick={() => { setFilter('all'); setPage(1); }}
          className={`px-4 py-2 rounded-lg font-medium transition-colors ${
            filter === 'all' 
              ? 'bg-blue-500 text-white' 
              : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
          }`}
        >
          Все
        </button>
      </div>

      {/* Список запросов */}
      <div className="space-y-4">
        {requests.map((request) => (
          <div key={request.id} className="bg-white p-6 rounded-lg shadow-md">
            <div className="flex justify-between items-start">
              <div className="flex-1">
                <div className="flex items-center gap-3 mb-2">
                  <h3 className="text-lg font-semibold text-gray-800">
                    {request.bot_user?.first_name || 'Пользователь'}
                    {request.bot_user?.last_name && ` ${request.bot_user.last_name}`}
                  </h3>
                  {getStatusBadge(request.status)}
                </div>
                
                <div className="space-y-1 text-sm text-gray-600">
                  {request.bot_user?.username && (
                    <p>📱 @{request.bot_user.username}</p>
                  )}
                  <p>🆔 Telegram ID: {request.bot_user?.telegram_user_id}</p>
                  <p>📅 Создан: {new Date(request.created_at).toLocaleString('ru-RU')}</p>
                  {request.reviewed_at && (
                    <p>✍️ Рассмотрен: {new Date(request.reviewed_at).toLocaleString('ru-RU')}</p>
                  )}
                  {request.reviewer && (
                    <p>👤 Рассмотрел: {request.reviewer.name}</p>
                  )}
                  {request.admin_comment && (
                    <p className="mt-2 p-2 bg-gray-100 rounded">
                      💬 {request.admin_comment}
                    </p>
                  )}
                </div>
              </div>

              {/* Кнопки действий */}
              {request.status === 'pending' && (
                <div className="flex gap-2">
                  <button
                    onClick={() => handleApprove(request.id)}
                    disabled={actionLoading === request.id}
                    className="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50 transition-colors"
                  >
                    {actionLoading === request.id ? '...' : '✅ Одобрить'}
                  </button>
                  <button
                    onClick={() => handleReject(request.id)}
                    disabled={actionLoading === request.id}
                    className="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 disabled:opacity-50 transition-colors"
                  >
                    {actionLoading === request.id ? '...' : '❌ Отклонить'}
                  </button>
                </div>
              )}
            </div>
          </div>
        ))}
      </div>

      {requests.length === 0 && (
        <div className="bg-white p-12 rounded-lg shadow-md text-center text-gray-500">
          <div className="text-4xl mb-4">📭</div>
          <p>Запросов не найдено</p>
        </div>
      )}

      {/* Пагинация */}
      {totalPages > 1 && (
        <div className="flex justify-center gap-2">
          <button
            onClick={() => setPage(p => Math.max(1, p - 1))}
            disabled={page === 1}
            className="px-4 py-2 bg-white border rounded-lg disabled:opacity-50 hover:bg-gray-50"
          >
            ← Назад
          </button>
          <span className="px-4 py-2 bg-white border rounded-lg">
            {page} / {totalPages}
          </span>
          <button
            onClick={() => setPage(p => Math.min(totalPages, p + 1))}
            disabled={page === totalPages}
            className="px-4 py-2 bg-white border rounded-lg disabled:opacity-50 hover:bg-gray-50"
          >
            Вперёд →
          </button>
        </div>
      )}
    </div>
  );
}

export default AdminRequests;
