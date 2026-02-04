import React, { useState, useEffect } from 'react';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function CompleteRaffleModal({ isOpen, onClose, botId, onComplete }) {
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [completing, setCompleting] = useState(false);
  const [selectedTicket, setSelectedTicket] = useState(null);
  const [notes, setNotes] = useState('');
  const [notifyWinner, setNotifyWinner] = useState(true);
  const [error, setError] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [raffle, setRaffle] = useState(null);

  useEffect(() => {
    if (isOpen && botId) {
      fetchParticipants();
    }
  }, [isOpen, botId]);

  const fetchParticipants = async () => {
    setLoading(true);
    setError(null);
    
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${botId}/raffles/participants`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setTickets(data.issued_tickets || []);
        setRaffle(data.raffle);
      } else {
        setError('Ошибка загрузки участников');
      }
    } catch (err) {
      console.error('Error fetching participants:', err);
      setError('Ошибка подключения');
    } finally {
      setLoading(false);
    }
  };

  const handleComplete = async () => {
    if (!selectedTicket) {
      setError('Выберите номерок победителя');
      return;
    }

    setCompleting(true);
    setError(null);

    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${botId}/raffles/complete`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          winner_ticket_id: selectedTicket.id,
          notes: notes,
          notify_winner: notifyWinner,
        }),
      });

      const data = await response.json();

      if (response.ok) {
        onComplete(data);
        onClose();
      } else {
        setError(data.message || 'Ошибка при завершении розыгрыша');
      }
    } catch (err) {
      console.error('Error completing raffle:', err);
      setError('Ошибка подключения');
    } finally {
      setCompleting(false);
    }
  };

  const filteredTickets = tickets.filter(ticket => {
    if (!searchTerm) return true;
    const search = searchTerm.toLowerCase();
    return (
      ticket.number.toString().includes(search) ||
      (ticket.user?.username && ticket.user.username.toLowerCase().includes(search)) ||
      (ticket.user?.first_name && ticket.user.first_name.toLowerCase().includes(search)) ||
      (ticket.user?.fio && ticket.user.fio.toLowerCase().includes(search))
    );
  });

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden">
        {/* Заголовок */}
        <div className="bg-gradient-to-r from-purple-500 to-indigo-600 px-6 py-4 text-white">
          <h2 className="text-2xl font-bold">🏆 Завершить розыгрыш</h2>
          {raffle && (
            <p className="text-sm opacity-80 mt-1">{raffle.name}</p>
          )}
        </div>

        {/* Содержимое */}
        <div className="p-6 overflow-y-auto max-h-[calc(90vh-200px)]">
          {error && (
            <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
              {error}
            </div>
          )}

          {loading ? (
            <div className="flex justify-center items-center h-48">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-purple-500"></div>
            </div>
          ) : tickets.length === 0 ? (
            <div className="text-center text-gray-500 py-12">
              <p className="text-lg">Нет выданных номерков</p>
              <p className="text-sm mt-2">Невозможно завершить розыгрыш без участников</p>
            </div>
          ) : (
            <>
              {/* Поиск */}
              <div className="mb-4">
                <input
                  type="text"
                  placeholder="🔍 Поиск по номеру или имени..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500"
                />
              </div>

              {/* Статистика */}
              <div className="bg-gray-50 rounded-lg p-4 mb-4">
                <div className="grid grid-cols-3 gap-4 text-center">
                  <div>
                    <div className="text-2xl font-bold text-purple-600">{tickets.length}</div>
                    <div className="text-sm text-gray-500">Всего номерков</div>
                  </div>
                  <div>
                    <div className="text-2xl font-bold text-green-600">
                      {new Set(tickets.map(t => t.user?.id)).size}
                    </div>
                    <div className="text-sm text-gray-500">Участников</div>
                  </div>
                  <div>
                    <div className="text-2xl font-bold text-blue-600">
                      {selectedTicket ? `№${selectedTicket.number}` : '—'}
                    </div>
                    <div className="text-sm text-gray-500">Выбран</div>
                  </div>
                </div>
              </div>

              {/* Список номерков */}
              <div className="border rounded-lg overflow-hidden mb-4">
                <div className="bg-gray-100 px-4 py-2 text-sm font-medium text-gray-600">
                  Выберите номерок победителя:
                </div>
                <div className="max-h-64 overflow-y-auto">
                  {filteredTickets.map((ticket) => (
                    <div
                      key={ticket.id}
                      onClick={() => setSelectedTicket(ticket)}
                      className={`flex items-center justify-between px-4 py-3 border-b cursor-pointer transition-colors ${
                        selectedTicket?.id === ticket.id
                          ? 'bg-purple-100 border-purple-300'
                          : 'hover:bg-gray-50'
                      }`}
                    >
                      <div className="flex items-center gap-4">
                        <div className={`w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg ${
                          selectedTicket?.id === ticket.id
                            ? 'bg-purple-500 text-white'
                            : 'bg-gray-200 text-gray-700'
                        }`}>
                          {ticket.number}
                        </div>
                        <div>
                          <div className="font-medium text-gray-900">
                            {ticket.user?.fio || ticket.user?.first_name || 'Пользователь'}
                          </div>
                          <div className="text-sm text-gray-500">
                            {ticket.user?.username ? `@${ticket.user.username}` : `ID: ${ticket.user?.telegram_user_id}`}
                          </div>
                        </div>
                      </div>
                      {selectedTicket?.id === ticket.id && (
                        <span className="text-purple-600 text-2xl">✓</span>
                      )}
                    </div>
                  ))}
                </div>
              </div>

              {/* Примечания */}
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Примечания (необязательно)
                </label>
                <textarea
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Комментарий к розыгрышу..."
                  rows={2}
                  className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500 resize-none"
                />
              </div>

              {/* Уведомление победителя */}
              <div className="flex items-center gap-3 mb-4">
                <input
                  type="checkbox"
                  id="notifyWinner"
                  checked={notifyWinner}
                  onChange={(e) => setNotifyWinner(e.target.checked)}
                  className="w-5 h-5 rounded text-purple-600 focus:ring-purple-500"
                />
                <label htmlFor="notifyWinner" className="text-gray-700">
                  Уведомить победителя в Telegram
                </label>
              </div>
            </>
          )}
        </div>

        {/* Кнопки */}
        <div className="px-6 py-4 bg-gray-50 border-t flex justify-end gap-3">
          <button
            onClick={onClose}
            disabled={completing}
            className="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors"
          >
            Отмена
          </button>
          <button
            onClick={handleComplete}
            disabled={completing || !selectedTicket || tickets.length === 0}
            className="px-6 py-2 bg-gradient-to-r from-purple-500 to-indigo-600 text-white rounded-lg font-medium hover:from-purple-600 hover:to-indigo-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {completing ? (
              <span className="flex items-center gap-2">
                <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
                Завершение...
              </span>
            ) : (
              '🏆 Завершить и выбрать победителя'
            )}
          </button>
        </div>
      </div>
    </div>
  );
}

export default CompleteRaffleModal;
