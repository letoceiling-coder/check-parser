import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function RaffleDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [raffle, setRaffle] = useState(null);
  const [participants, setParticipants] = useState([]);
  const [stats, setStats] = useState(null);
  const [winnerParticipantFio, setWinnerParticipantFio] = useState(null);
  const [botId, setBotId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    fetchBot();
  }, []);

  useEffect(() => {
    if (botId && id) fetchRaffle();
  }, [botId, id]);

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
        if (bots.length > 0) setBotId(bots[0].id);
      }
    } catch (err) {
      console.error(err);
      setError('Ошибка загрузки');
    }
  };

  const fetchRaffle = async () => {
    setLoading(true);
    setError(null);
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${botId}/raffles/${id}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });
      if (!response.ok) {
        setError('Розыгрыш не найден');
        setLoading(false);
        return;
      }
      const data = await response.json();
      setRaffle(data.raffle);
      setParticipants(data.participants || []);
      setStats(data.stats || null);
      setWinnerParticipantFio(data.winner_participant_fio || null);
    } catch (err) {
      console.error(err);
      setError('Ошибка загрузки');
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
      paused: { bg: 'bg-amber-100', text: 'text-amber-800', label: '⏸ Приостановлен' },
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

  const downloadExcel = async () => {
    if (!botId || !raffle || exporting) return;
    setExporting(true);
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${botId}/raffles/${raffle.id}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });
      if (!response.ok) throw new Error('Ошибка загрузки');
      const data = await response.json();
      const list = data.participants || [];
      const headerRow = ['телефон', 'фамилия имя отчество', 'номерки'];
      const dataRows = list.map((p) => {
        const phone = p.phone ?? '';
        const fio = p.fio ?? '';
        const numbers = (p.tickets || []).map((t) => t.number).sort((a, b) => a - b).join(', ');
        return [phone, fio, numbers];
      });
      const wsData = [headerRow, ...dataRows];
      const xlsxMod = await import('xlsx').catch(() => null);
      const XLSX = xlsxMod?.default || xlsxMod;
      if (!XLSX?.utils) {
        setError('Модуль экспорта недоступен');
        return;
      }
      const ws = XLSX.utils.aoa_to_sheet(wsData);
      const wb = XLSX.utils.book_new();
      const safeName = (raffle.name || `Розыгрыш_${raffle.id}`).replace(/[\[\]\\/*?:]/g, '_').slice(0, 31);
      XLSX.utils.book_append_sheet(wb, ws, safeName);
      XLSX.writeFile(wb, `участники_${safeName}.xlsx`);
    } catch (err) {
      console.error(err);
      setError('Не удалось скачать Excel');
    } finally {
      setExporting(false);
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-purple-500"></div>
      </div>
    );
  }

  if (error && !raffle) {
    return (
      <div className="space-y-4">
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{error}</div>
        <button
          type="button"
          onClick={() => navigate('/raffles')}
          className="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300"
        >
          ← К списку розыгрышей
        </button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-3">
        <button
          type="button"
          onClick={() => navigate('/raffles')}
          className="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 font-medium"
        >
          ← К списку розыгрышей
        </button>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{error}</div>
      )}

      {/* Карточка розыгрыша */}
      <div className="bg-white rounded-xl shadow-md overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-gray-800">{raffle?.name || `Розыгрыш #${id}`}</h1>
            <p className="text-sm text-gray-500 mt-1">ID: {raffle?.id}</p>
          </div>
          <div className="flex items-center gap-3">
            {raffle && getStatusBadge(raffle.status)}
            <button
              type="button"
              onClick={downloadExcel}
              disabled={exporting}
              className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 text-sm font-medium"
            >
              {exporting ? '⏳ Загрузка...' : '📥 Скачать Excel'}
            </button>
          </div>
        </div>
        <div className="px-6 py-4 bg-gray-50">
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
            <div>
              <span className="text-gray-500">Начат</span>
              <div className="font-medium">{formatDate(raffle?.started_at)}</div>
            </div>
            {raffle?.completed_at && (
              <div>
                <span className="text-gray-500">Завершён</span>
                <div className="font-medium">{formatDate(raffle.completed_at)}</div>
              </div>
            )}
            <div>
              <span className="text-gray-500">Участников</span>
              <div className="font-medium">{stats?.total_participants ?? participants.length}</div>
            </div>
            <div>
              <span className="text-gray-500">Номерков</span>
              <div className="font-medium">{stats?.tickets_issued ?? 0} / {raffle?.total_slots ?? 0}</div>
            </div>
            <div>
              <span className="text-gray-500">Сумма</span>
              <div className="font-medium">{formatMoney(stats?.total_revenue ?? raffle?.total_revenue)}</div>
            </div>
            {raffle?.winner_user && (
              <div>
                <span className="text-gray-500">Победитель</span>
                <div className="font-medium text-green-600">
                  №{raffle.winner_ticket_number} — {raffle.winner_user.first_name || raffle.winner_user.username || '—'}
                  {winnerParticipantFio && (
                    <span className="text-gray-600 font-normal"> ({winnerParticipantFio})</span>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Участники */}
      <div className="bg-white rounded-xl shadow-md overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
          <h2 className="text-lg font-semibold text-gray-800">Участники розыгрыша</h2>
        </div>
        {participants.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Участников пока нет</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Телефон</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ФИО</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Номерки</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase w-28">Победитель</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {participants.map((p) => (
                  <tr key={p.id} className={`hover:bg-gray-50 ${p.is_winner ? 'bg-green-50' : ''}`}>
                    <td className="px-6 py-3 text-gray-800">{p.phone || '—'}</td>
                    <td className="px-6 py-3 text-gray-800">{p.fio || '—'}</td>
                    <td className="px-6 py-3 text-gray-600">
                      {(p.tickets || []).map((t) => t.number).sort((a, b) => a - b).join(', ') || '—'}
                    </td>
                    <td className="px-6 py-3">
                      {p.is_winner ? (
                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                          🏆 Победитель
                        </span>
                      ) : (
                        '—'
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

export default RaffleDetail;
