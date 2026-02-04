import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function BotUsers() {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [selectedUser, setSelectedUser] = useState(null);
  const [userDetails, setUserDetails] = useState(null);
  const [loadingDetails, setLoadingDetails] = useState(false);

  useEffect(() => {
    fetchUsers();
  }, [page, filter, search]);

  const fetchUsers = async () => {
    try {
      const token = localStorage.getItem('token');
      const params = new URLSearchParams({
        page,
        per_page: 20,
        ...(filter !== 'all' && { role: filter }),
        ...(search && { search }),
      });

      const response = await fetch(`${API_URL}/api/bot-users?${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setUsers(data.data || []);
        setTotalPages(data.last_page || 1);
      }
    } catch (error) {
      console.error('Error fetching users:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchUserDetails = async (userId) => {
    setLoadingDetails(true);
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot-users/${userId}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setUserDetails(data);
        setSelectedUser(userId);
      }
    } catch (error) {
      console.error('Error fetching user details:', error);
    } finally {
      setLoadingDetails(false);
    }
  };

  const getRoleBadge = (role) => {
    switch (role) {
      case 'admin':
        return <span className="px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-800">👑 Админ</span>;
      case 'user':
        return <span className="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">👤 Пользователь</span>;
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
      <h1 className="text-3xl font-bold text-gray-800">👥 Пользователи бота</h1>

      {/* Фильтры */}
      <div className="bg-white p-4 rounded-lg shadow-md flex flex-wrap gap-4 items-center">
        <div className="flex gap-2">
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
          <button
            onClick={() => { setFilter('admin'); setPage(1); }}
            className={`px-4 py-2 rounded-lg font-medium transition-colors ${
              filter === 'admin' 
                ? 'bg-purple-500 text-white' 
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
            }`}
          >
            👑 Админы
          </button>
          <button
            onClick={() => { setFilter('user'); setPage(1); }}
            className={`px-4 py-2 rounded-lg font-medium transition-colors ${
              filter === 'user' 
                ? 'bg-green-500 text-white' 
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
            }`}
          >
            👤 Пользователи
          </button>
        </div>
        <div className="flex-1">
          <input
            type="text"
            placeholder="Поиск по имени или username..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
          />
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Список пользователей */}
        <div className="lg:col-span-2 space-y-4">
          {users.map((user) => (
            <div 
              key={user.id} 
              className={`bg-white p-4 rounded-lg shadow-md cursor-pointer transition-all hover:shadow-lg ${
                selectedUser === user.id ? 'ring-2 ring-blue-500' : ''
              }`}
              onClick={() => fetchUserDetails(user.id)}
            >
              <div className="flex justify-between items-start">
                <div>
                  <div className="flex items-center gap-2 mb-1">
                    <h3 className="font-semibold text-gray-800">{user.display_name}</h3>
                    {getRoleBadge(user.role)}
                  </div>
                  {user.username && (
                    <p className="text-sm text-gray-500">@{user.username}</p>
                  )}
                  <p className="text-xs text-gray-400 mt-1">ID: {user.telegram_user_id}</p>
                </div>
                <div className="text-right">
                  <div className="flex items-center gap-2 text-sm">
                    <span className="text-purple-600">🎫 {user.tickets_count}</span>
                    <span className="text-blue-600">🧾 {user.checks_count}</span>
                  </div>
                  <p className="text-xs text-gray-400 mt-1">
                    {new Date(user.created_at).toLocaleDateString('ru-RU')}
                  </p>
                </div>
              </div>
              {user.fsm_state && user.fsm_state !== 'IDLE' && (
                <div className="mt-2 text-xs text-orange-600 bg-orange-50 px-2 py-1 rounded inline-block">
                  📍 {user.fsm_state}
                </div>
              )}
            </div>
          ))}

          {users.length === 0 && (
            <div className="bg-white p-12 rounded-lg shadow-md text-center text-gray-500">
              <div className="text-4xl mb-4">👥</div>
              <p>Пользователей не найдено</p>
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

        {/* Детали пользователя */}
        <div className="lg:col-span-1">
          {loadingDetails && (
            <div className="bg-white p-6 rounded-lg shadow-md flex justify-center">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
            </div>
          )}

          {userDetails && !loadingDetails && (
            <div className="bg-white p-6 rounded-lg shadow-md sticky top-4">
              <h2 className="text-xl font-bold text-gray-800 mb-4">
                👤 {userDetails.display_name}
              </h2>
              
              <div className="space-y-4">
                {/* Основная информация */}
                <div className="space-y-2 text-sm">
                  {userDetails.username && (
                    <p>📱 @{userDetails.username}</p>
                  )}
                  <p>🆔 Telegram ID: {userDetails.telegram_user_id}</p>
                  <p>👤 Роль: {getRoleBadge(userDetails.role)}</p>
                  <p>📅 Регистрация: {new Date(userDetails.created_at).toLocaleString('ru-RU')}</p>
                </div>

                {/* Персональные данные */}
                {userDetails.fio && (
                  <div className="p-3 bg-gray-50 rounded-lg">
                    <h3 className="font-medium text-gray-700 mb-2">📋 Персональные данные</h3>
                    <div className="space-y-1 text-sm">
                      <p><strong>ФИО:</strong> {userDetails.fio}</p>
                      <p><strong>Телефон:</strong> {userDetails.phone}</p>
                      <p><strong>ИНН:</strong> {userDetails.inn}</p>
                    </div>
                  </div>
                )}

                {/* Номерки */}
                {userDetails.tickets && userDetails.tickets.length > 0 && (
                  <div className="p-3 bg-purple-50 rounded-lg">
                    <h3 className="font-medium text-purple-700 mb-2">
                      🎫 Номерки ({userDetails.tickets_count})
                    </h3>
                    <div className="flex flex-wrap gap-2">
                      {userDetails.tickets.map((num) => (
                        <span key={num} className="px-2 py-1 bg-purple-200 text-purple-800 rounded text-sm font-medium">
                          #{num}
                        </span>
                      ))}
                    </div>
                  </div>
                )}

                {/* Чеки */}
                {userDetails.checks && userDetails.checks.length > 0 && (
                  <div className="p-3 bg-blue-50 rounded-lg">
                    <h3 className="font-medium text-blue-700 mb-2">
                      🧾 Чеки ({userDetails.checks_count})
                    </h3>
                    <div className="space-y-2">
                      {userDetails.checks.map((check) => (
                        <Link
                          key={check.id}
                          to={`/checks/${check.id}`}
                          className="block p-2 bg-white rounded hover:bg-blue-100 transition-colors"
                        >
                          <div className="flex justify-between text-sm">
                            <span>#{check.id}</span>
                            <span className={
                              check.review_status === 'approved' ? 'text-green-600' :
                              check.review_status === 'rejected' ? 'text-red-600' :
                              'text-yellow-600'
                            }>
                              {check.review_status === 'approved' ? '✅' :
                               check.review_status === 'rejected' ? '❌' : '⏳'}
                            </span>
                          </div>
                          {check.amount && (
                            <div className="text-xs text-gray-500">
                              {Number(check.amount).toLocaleString('ru-RU')} ₽
                            </div>
                          )}
                        </Link>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}

          {!userDetails && !loadingDetails && (
            <div className="bg-white p-6 rounded-lg shadow-md text-center text-gray-500">
              <div className="text-4xl mb-2">👆</div>
              <p>Выберите пользователя для просмотра деталей</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

export default BotUsers;
