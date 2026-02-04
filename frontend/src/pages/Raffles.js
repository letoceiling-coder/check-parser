import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function Raffles() {
  const [raffles, setRaffles] = useState([]);
  const [currentRaffle, setCurrentRaffle] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [botId, setBotId] = useState(null);
  const navigate = useNavigate();

  useEffect(() => {
    fetchBot();
  }, []);

  useEffect(() => {
    if (botId) {
      fetchRaffles();
    }
  }, [botId]);

  const fetchBot = async () => {
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
  };

  const fetchRaffles = async () => {
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${botId}/raffles`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setRaffles(data.raffles || []);
        setCurrentRaffle(data.current_raffle);
      }
    } catch (err) {
      console.error('Error fetching raffles:', err);
      setError('Ошибка загрузки розыгрышей');
    } finally {
      setLoading(false);
    }
  };

  const formatDate = (dateString) => {
    if (!dateString) return '—';
    return new Date(dateString).toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const formatMoney = (amount) => {
    if (!amount) return '0 ₽';
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency: 'RUB',
      minimumFractionDigits: 0,
    }).format(amount);
  };

  const getStatusBadge = (status) => {
    const badges = {
      active: { bg: 'bg-green-100', text: 'text-green-800', label: '🟢 Активный' },
      completed: { bg: 'bg-blue-100', text: 'text-blue-800', label: '✅ Завершён' },
      cancelled: { bg: 'bg-gray-100', text: 'text-gray-800', label: '❌ Отменён' },
    };
    const badge = badges[status] || badges.active;
    return (
      <span className={`px-2 py-1 rounded-full text-xs font-medium ${badge.bg} ${badge.text}`}>
        {badge.label}
      </span>
    );
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-purple-500"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Заголовок */}
      <div className="flex justify-between items-center">
        <h1 className="text-3xl font-bold text-gray-800">🎰 История розыгрышей</h1>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
          {error}
        </div>
      )}

      {/* Текущий розыгрыш */}
      {currentRaffle && (
        <div className="bg-gradient-to-r from-purple-500 to-indigo-600 rounded-xl p-6 text-white shadow-lg">
          <div className="flex justify-between items-start">
            <div>
              <h2 className="text-2xl font-bold mb-2">{currentRaffle.name}</h2>
              <p className="opacity-80">Начат: {formatDate(currentRaffle.started_at)}</p>
            </div>
            {getStatusBadge(currentRaffle.status)}
          </div>
          
          <div className="grid grid-cols-4 gap-4 mt-6">
            <div className="bg-white/20 rounded-lg p-4">
              <div className="text-3xl font-bold">{currentRaffle.total_participants || 0}</div>
              <div className="text-sm opacity-80">Участников</div>
            </div>
            <div className="bg-white/20 rounded-lg p-4">
              <div className="text-3xl font-bold">{currentRaffle.tickets_issued || 0}</div>
              <div className="text-sm opacity-80">Выдано номерков</div>
            </div>
            <div className="bg-white/20 rounded-lg p-4">
              <div className="text-3xl font-bold">{formatMoney(currentRaffle.total_revenue)}</div>
              <div className="text-sm opacity-80">Сумма оплат</div>
            </div>
            <div className="bg-white/20 rounded-lg p-4">
              <div className="text-3xl font-bold">{currentRaffle.checks_count || 0}</div>
              <div className="text-sm opacity-80">Чеков</div>
            </div>
          </div>

          <div className="mt-6 flex gap-3">
            <button
              onClick={() => navigate('/checks')}
              className="px-4 py-2 bg-white text-purple-600 rounded-lg font-medium hover:bg-gray-100 transition-colors"
            >
              📋 Перейти к чекам
            </button>
          </div>
        </div>
      )}

      {/* Список прошлых розыгрышей */}
      <div className="bg-white rounded-xl shadow-md overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
          <h3 className="text-lg font-semibold text-gray-800">📜 Все розыгрыши</h3>
        </div>
        
        {raffles.length === 0 ? (
          <div className="p-8 text-center text-gray-500">
            <p className="text-lg">Розыгрышей пока нет</p>
            <p className="text-sm mt-2">Первый розыгрыш будет создан автоматически при первом чеке</p>
          </div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Название</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Участники</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Номерков</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Сумма</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Победитель</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Даты</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {raffles.map((raffle) => (
                <tr 
                  key={raffle.id} 
                  className={`hover:bg-gray-50 cursor-pointer ${raffle.status === 'active' ? 'bg-green-50' : ''}`}
                  onClick={() => navigate(`/raffles/${raffle.id}`)}
                >
                  <td className="px-6 py-4">
                    <div className="font-medium text-gray-900">{raffle.name}</div>
                    <div className="text-sm text-gray-500">ID: {raffle.id}</div>
                  </td>
                  <td className="px-6 py-4">
                    {getStatusBadge(raffle.status)}
                  </td>
                  <td className="px-6 py-4 text-gray-600">
                    {raffle.total_participants || 0}
                  </td>
                  <td className="px-6 py-4 text-gray-600">
                    {raffle.tickets_issued || 0} / {raffle.total_slots}
                  </td>
                  <td className="px-6 py-4 text-gray-600">
                    {formatMoney(raffle.total_revenue)}
                  </td>
                  <td className="px-6 py-4">
                    {raffle.winner_user ? (
                      <div>
                        <div className="font-medium text-green-600">
                          🏆 №{raffle.winner_ticket_number}
                        </div>
                        <div className="text-sm text-gray-500">
                          {raffle.winner_user.first_name || raffle.winner_user.username || 'Пользователь'}
                        </div>
                      </div>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </td>
                  <td className="px-6 py-4 text-sm text-gray-500">
                    <div>Начат: {formatDate(raffle.started_at)}</div>
                    {raffle.completed_at && (
                      <div>Завершён: {formatDate(raffle.completed_at)}</div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

export default Raffles;
