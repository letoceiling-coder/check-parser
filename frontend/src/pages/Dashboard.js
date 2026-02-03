import React from 'react';

function Dashboard() {
  return (
    <div className="animate-fade-in">
      <div className="bg-white rounded-lg shadow-md p-6 mb-6">
        <h1 className="text-3xl font-bold text-gray-800 mb-4">
          Добро пожаловать в панель управления
        </h1>
        <p className="text-gray-600">
          Используйте меню слева для навигации по разделам
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div className="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow duration-300">
          <div className="text-4xl mb-4">📊</div>
          <h2 className="text-xl font-semibold text-gray-800 mb-2">Статистика</h2>
          <p className="text-gray-600">Просмотр статистики и аналитики</p>
        </div>

        <div className="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow duration-300">
          <div className="text-4xl mb-4">⚙️</div>
          <h2 className="text-xl font-semibold text-gray-800 mb-2">Настройки</h2>
          <p className="text-gray-600">Управление настройками системы</p>
        </div>

        <div className="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow duration-300">
          <div className="text-4xl mb-4">🤖</div>
          <h2 className="text-xl font-semibold text-gray-800 mb-2">Бот</h2>
          <p className="text-gray-600">Настройка Telegram бота</p>
        </div>
      </div>
    </div>
  );
}

export default Dashboard;
