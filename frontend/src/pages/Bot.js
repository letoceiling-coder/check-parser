import React, { useState, useEffect } from 'react';
import BotSettings from '../components/BotSettings';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function Bot() {
  const [bot, setBot] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

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
        setBot(data);
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
    <div className="animate-fade-in">
      <div className="bg-white rounded-lg shadow-md p-6 mb-6">
        <h1 className="text-3xl font-bold text-gray-800 mb-2">
          Настройки Telegram бота
        </h1>
        <p className="text-gray-600">
          Создайте и настройте вашего Telegram бота
        </p>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
          {error}
        </div>
      )}

      <BotSettings bot={bot} onBotCreated={handleBotCreated} onUpdate={fetchBot} />
    </div>
  );
}

export default Bot;
