import React, { useState } from 'react';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function NewRaffleModal({ isOpen, onClose, botId, onCreated }) {
  const [name, setName] = useState('');
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState(null);

  const handleCreate = async () => {
    setCreating(true);
    setError(null);

    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${botId}/raffles/reset`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          name: name || null,
        }),
      });

      const data = await response.json();

      if (response.ok) {
        onCreated(data);
        onClose();
        setName('');
      } else {
        setError(data.message || 'Ошибка при создании розыгрыша');
      }
    } catch (err) {
      console.error('Error creating raffle:', err);
      setError('Ошибка подключения');
    } finally {
      setCreating(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden">
        {/* Заголовок */}
        <div className="bg-gradient-to-r from-green-500 to-emerald-600 px-6 py-4 text-white">
          <h2 className="text-2xl font-bold">🎯 Новый розыгрыш</h2>
          <p className="text-sm opacity-80 mt-1">Все номерки будут сброшены</p>
        </div>

        {/* Содержимое */}
        <div className="p-6">
          {error && (
            <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
              {error}
            </div>
          )}

          <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
            <div className="flex gap-3">
              <span className="text-2xl">⚠️</span>
              <div>
                <p className="font-medium text-yellow-800">Внимание!</p>
                <p className="text-sm text-yellow-700 mt-1">
                  При создании нового розыгрыша все текущие номерки будут сброшены 
                  и отвязаны от пользователей. Убедитесь, что вы завершили текущий розыгрыш.
                </p>
              </div>
            </div>
          </div>

          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Название розыгрыша (необязательно)
            </label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Например: Розыгрыш февраль 2026"
              className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
            />
            <p className="text-sm text-gray-500 mt-1">
              Если не указать, название будет сгенерировано автоматически
            </p>
          </div>
        </div>

        {/* Кнопки */}
        <div className="px-6 py-4 bg-gray-50 border-t flex justify-end gap-3">
          <button
            onClick={onClose}
            disabled={creating}
            className="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors"
          >
            Отмена
          </button>
          <button
            onClick={handleCreate}
            disabled={creating}
            className="px-6 py-2 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-lg font-medium hover:from-green-600 hover:to-emerald-700 transition-colors disabled:opacity-50"
          >
            {creating ? (
              <span className="flex items-center gap-2">
                <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
                Создание...
              </span>
            ) : (
              '🚀 Начать новый розыгрыш'
            )}
          </button>
        </div>
      </div>
    </div>
  );
}

export default NewRaffleModal;
