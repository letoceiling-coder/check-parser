import React, { useState, useEffect } from 'react';
import BotSettings from '../components/BotSettings';
import RaffleSettings from '../components/RaffleSettings';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function Bot() {
  const [bot, setBot] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [activeSection, setActiveSection] = useState('bot');

  useEffect(() => {
    fetchBot();
  }, []);

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
        const data = await response.json();
        // API может вернуть массив с одним ботом или один объект
        const botData = Array.isArray(data) ? (data[0] ?? null) : data;
        setBot(botData);
      } else if (response.status === 404) {
        setBot(null);
      } else {
        setError('Ошибка загрузки данных бота');
      }
    } catch (err) {
      setError('Ошибка подключения к серверу');
    } finally {
      setLoading(false);
    }
  };

  const handleBotCreated = (newBot) => {
    setBot(newBot);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  return (
    <div className="animate-fade-in space-y-6">
      <div className="bg-white rounded-lg shadow-md p-6">
        <h1 className="text-3xl font-bold text-gray-800 mb-2">
          🤖 Настройки Telegram бота
        </h1>
        <p className="text-gray-600">
          Создайте и настройте вашего Telegram бота
        </p>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
          {error}
        </div>
      )}

      {/* Навигация по разделам */}
      {bot && (
        <div className="flex gap-2 bg-white p-2 rounded-lg shadow-md">
          <button
            onClick={() => setActiveSection('bot')}
            className={`flex-1 px-4 py-3 rounded-lg font-medium transition-all ${
              activeSection === 'bot'
                ? 'bg-blue-500 text-white shadow-md'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
            }`}
          >
            🔧 Основные настройки
          </button>
          <button
            onClick={() => setActiveSection('raffle')}
            className={`flex-1 px-4 py-3 rounded-lg font-medium transition-all ${
              activeSection === 'raffle'
                ? 'bg-purple-500 text-white shadow-md'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
            }`}
          >
            🎯 Розыгрыш номерков
          </button>
        </div>
      )}

      {/* Содержимое разделов */}
      {activeSection === 'bot' && (
        <BotSettings bot={bot} onBotCreated={handleBotCreated} onUpdate={fetchBot} />
      )}

      {activeSection === 'raffle' && bot && (
        <RaffleSettings bot={bot} />
      )}

      {!bot && (
        <div className="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg">
          <p className="font-medium">👆 Сначала создайте бота</p>
          <p className="text-sm">После создания бота станут доступны настройки розыгрыша</p>
        </div>
      )}
    </div>
  );
}

export default Bot;
