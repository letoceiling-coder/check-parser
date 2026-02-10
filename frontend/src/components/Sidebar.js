import React from 'react';
import { Link, useLocation } from 'react-router-dom';

function Sidebar({ isOpen, onToggle, user, onLogout }) {
  const location = useLocation();

  const menuItems = [
    { id: 'dashboard', label: 'Главная', icon: '🏠', path: '/' },
    { id: 'checks', label: 'Чеки', icon: '🧾', path: '/checks' },
    { id: 'tickets', label: 'Номерки', icon: '🎫', path: '/tickets' },
    { id: 'raffles', label: 'Розыгрыши', icon: '🎰', path: '/raffles' },
    { id: 'admin-requests', label: 'Запросы на роли', icon: '👤', path: '/admin-requests' },
    { id: 'bot-users', label: 'Пользователи', icon: '👥', path: '/bot-users' },
    { id: 'bot', label: 'Настройки бота', icon: '🤖', path: '/bot' },
    { id: 'google-sheets', label: 'Google Sheets', icon: '📊', path: '/google-sheets' },
    { id: 'broadcast', label: 'Рассылка', icon: '📢', path: '/broadcast' },
    { id: 'documentation', label: 'Документация', icon: '📚', path: '/documentation' },
  ];

  return (
    <aside className={`fixed left-0 top-0 h-full bg-white shadow-lg transition-all duration-300 z-40 ${
      isOpen ? 'w-64' : 'w-16'
    }`}>
      <div className="flex flex-col h-full">
        {/* Header */}
        <div className="p-4 border-b border-gray-200 flex items-center justify-between">
          {isOpen && (
            <h1 className="text-xl font-bold text-gray-800 animate-fade-in">
              Панель управления
            </h1>
          )}
          <button
            onClick={onToggle}
            className="p-2 rounded-lg hover:bg-gray-100 transition-colors duration-200"
          >
            <svg className="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              {isOpen ? (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              ) : (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
              )}
            </svg>
          </button>
        </div>

        {/* User Info */}
        {isOpen && user && (
          <div className="p-4 border-b border-gray-200 animate-fade-in">
            <div className="flex items-center space-x-3">
              <div className="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center text-white font-semibold">
                {user.name.charAt(0).toUpperCase()}
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900 truncate">{user.name}</p>
                <p className="text-xs text-gray-500 truncate">@{user.username}</p>
              </div>
            </div>
          </div>
        )}

        {/* Menu Items */}
        <nav className="flex-1 p-4 space-y-2">
          {menuItems.map((item) => {
            const isActive = location.pathname === item.path;
            return (
              <Link
                key={item.id}
                to={item.path}
onClick={() => {}}
                className={`flex items-center space-x-3 px-4 py-3 rounded-lg transition-all duration-200 ${
                  isActive
                    ? 'bg-blue-500 text-white shadow-md transform scale-105'
                    : 'text-gray-700 hover:bg-gray-100 hover:transform hover:scale-105'
                }`}
              >
                <span className="text-xl">{item.icon}</span>
                {isOpen && (
                  <span className="font-medium animate-fade-in">{item.label}</span>
                )}
              </Link>
            );
          })}
        </nav>

        {/* Logout Button */}
        <div className="p-4 border-t border-gray-200">
          <button
            onClick={onLogout}
            className="w-full flex items-center space-x-3 px-4 py-3 rounded-lg text-red-600 hover:bg-red-50 transition-all duration-200"
          >
            <span className="text-xl">🚪</span>
            {isOpen && <span className="font-medium animate-fade-in">Выход</span>}
          </button>
        </div>
      </div>
    </aside>
  );
}

export default Sidebar;
