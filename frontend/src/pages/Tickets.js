import React, { useState, useEffect } from 'react';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function Tickets() {
  const [tickets, setTickets] = useState([]);
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  useEffect(() => {
    fetchTickets();
    fetchStats();
  }, [page, filter, search]);

  const fetchTickets = async () => {
    try {
      const token = localStorage.getItem('token');
      const params = new URLSearchParams({
        page,
        per_page: 50,
        ...(filter !== 'all' && { status: filter }),
        ...(search && { search }),
      });

      const response = await fetch(`${API_URL}/api/tickets?${params}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setTickets(data.data || []);
        setTotalPages(data.last_page || 1);
      }
    } catch (error) {
      console.error('Error fetching tickets:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchStats = async () => {
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/tickets/stats`, {
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
      <h1 className="text-3xl font-bold text-gray-800">🎫 Номерки</h1>

      {/* Статистика */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="bg-white p-6 rounded-lg shadow-md">
            <div className="text-3xl font-bold text-gray-800">{stats.total}</div>
            <div className="text-sm text-gray-500">Всего номерков</div>
          </div>
          <div className="bg-white p-6 rounded-lg shadow-md">
            <div className="text-3xl font-bold text-green-600">{stats.issued}</div>
            <div className="text-sm text-gray-500">Выдано</div>
          </div>
          <div className="bg-white p-6 rounded-lg shadow-md">
            <div className="text-3xl font-bold text-blue-600">{stats.available}</div>
            <div className="text-sm text-gray-500">Свободно</div>
          </div>
          <div className="bg-white p-6 rounded-lg shadow-md">
            <div className="text-3xl font-bold text-purple-600">{stats.percentage_issued}%</div>
            <div className="text-sm text-gray-500">Заполненность</div>
          </div>
        </div>
      )}

      {/* Прогресс-бар */}
      {stats && (
        <div className="bg-white p-4 rounded-lg shadow-md">
          <div className="flex justify-between mb-2">
            <span className="text-sm font-medium text-gray-700">Заполненность розыгрыша</span>
            <span className="text-sm font-medium text-gray-700">{stats.issued} / {stats.total}</span>
          </div>
          <div className="w-full bg-gray-200 rounded-full h-4">
            <div 
              className="bg-gradient-to-r from-blue-500 to-purple-500 h-4 rounded-full transition-all duration-500"
              style={{ width: `${stats.percentage_issued}%` }}
            ></div>
          </div>
        </div>
      )}

      {/* Фильтры */}
      <div className="bg-white p-4 rounded-lg shadow-md flex flex-wrap gap-4 items-center">
        <div>
          <select
            value={filter}
            onChange={(e) => { setFilter(e.target.value); setPage(1); }}
            className="px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
          >
            <option value="all">Все номерки</option>
            <option value="issued">Выданные</option>
            <option value="available">Свободные</option>
          </select>
        </div>
        <div className="flex-1">
          <input
            type="text"
            placeholder="Поиск по номеру или пользователю..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
          />
        </div>
      </div>

      {/* Таблица номерков */}
      <div className="bg-white rounded-lg shadow-md overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Номер
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Статус
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Владелец
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Чек
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Дата выдачи
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {tickets.map((ticket) => (
                <tr key={ticket.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className="text-lg font-bold text-gray-800">#{ticket.number}</span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    {ticket.bot_user_id ? (
                      <span className="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">
                        Выдан
                      </span>
                    ) : (
                      <span className="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                        Свободен
                      </span>
                    )}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    {ticket.bot_user ? (
                      <div>
                        <div className="text-sm font-medium text-gray-900">
                          {ticket.bot_user.first_name || ticket.bot_user.username || 'Без имени'}
                        </div>
                        {ticket.bot_user.username && (
                          <div className="text-sm text-gray-500">@{ticket.bot_user.username}</div>
                        )}
                      </div>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    {ticket.check_id ? (
                      <a 
                        href={`/checks/${ticket.check_id}`}
                        className="text-blue-600 hover:text-blue-800"
                      >
                        #{ticket.check_id}
                      </a>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {ticket.issued_at 
                      ? new Date(ticket.issued_at).toLocaleString('ru-RU')
                      : '—'
                    }
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {tickets.length === 0 && (
          <div className="text-center py-12 text-gray-500">
            Номерки не найдены
          </div>
        )}
      </div>

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

      {/* Топ пользователей */}
      {stats?.top_users && stats.top_users.length > 0 && (
        <div className="bg-white p-6 rounded-lg shadow-md">
          <h2 className="text-xl font-bold text-gray-800 mb-4">🏆 Топ участников</h2>
          <div className="space-y-3">
            {stats.top_users.map((item, index) => (
              <div key={item.bot_user_id} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div className="flex items-center gap-3">
                  <span className="text-2xl">
                    {index === 0 ? '🥇' : index === 1 ? '🥈' : index === 2 ? '🥉' : `#${index + 1}`}
                  </span>
                  <div>
                    <div className="font-medium">
                      {item.bot_user?.first_name || item.bot_user?.username || 'Пользователь'}
                    </div>
                    {item.bot_user?.username && (
                      <div className="text-sm text-gray-500">@{item.bot_user.username}</div>
                    )}
                  </div>
                </div>
                <div className="text-lg font-bold text-purple-600">
                  {item.tickets_count} 🎫
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

export default Tickets;
