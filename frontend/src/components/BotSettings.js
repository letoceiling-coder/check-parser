import React, { useState, useEffect } from 'react';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

// Generate default webhook URL based on current domain
const getDefaultWebhookUrl = () => {
  const origin = window.location.origin;
  return `${origin}/api/telegram/webhook`;
};

function BotSettings({ bot, onBotCreated, onUpdate }) {
  const [formData, setFormData] = useState({
    token: bot?.token || '',
  });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(null);
  const [testing, setTesting] = useState(false);
  
  // Состояние для приветственного сообщения
  const [welcomeMessage, setWelcomeMessage] = useState('');
  const [defaultWelcomeMessage, setDefaultWelcomeMessage] = useState('');
  const [savingWelcome, setSavingWelcome] = useState(false);
  const [welcomeSuccess, setWelcomeSuccess] = useState(null);
  const [welcomeError, setWelcomeError] = useState(null);

  // Состояние для описания бота (Bot Description)
  const [botDescription, setBotDescription] = useState('');
  const [botShortDescription, setBotShortDescription] = useState('');
  const [loadingDescription, setLoadingDescription] = useState(false);
  const [savingDescription, setSavingDescription] = useState(false);
  const [descriptionSuccess, setDescriptionSuccess] = useState(null);
  const [descriptionError, setDescriptionError] = useState(null);

  useEffect(() => {
    if (bot) {
      setWelcomeMessage(bot.welcome_message || '');
      setDefaultWelcomeMessage(bot.default_welcome_message || '');
      // Загружаем описание бота из Telegram
      fetchBotDescription();
    }
  }, [bot]);

  const fetchBotDescription = async () => {
    if (!bot) return;
    
    setLoadingDescription(true);
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${bot.id}/description`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setBotDescription(data.description || '');
        setBotShortDescription(data.short_description || '');
      }
    } catch (err) {
      console.error('Error fetching bot description:', err);
    } finally {
      setLoadingDescription(false);
    }
  };

  const handleChange = (e) => {
    setFormData({
      ...formData,
      [e.target.name]: e.target.value,
    });
    setError(null);
    setSuccess(null);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    setSuccess(null);

    try {
      const token = localStorage.getItem('token');
      const url = bot 
        ? `${API_URL}/api/bot/${bot.id}`
        : `${API_URL}/api/bot`;
      
      const method = bot ? 'PUT' : 'POST';

      const response = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
        body: JSON.stringify(formData),
      });

      const data = await response.json();

      if (response.ok) {
        setSuccess(bot ? 'Бот успешно обновлен!' : 'Бот успешно создан! Webhook зарегистрирован.');
        if (onBotCreated) {
          onBotCreated(data);
        }
        if (onUpdate) {
          onUpdate();
        }
      } else {
        setError(data.message || 'Ошибка при сохранении бота');
      }
    } catch (err) {
      setError('Ошибка подключения к серверу');
    } finally {
      setLoading(false);
    }
  };

  const handleTestWebhook = async () => {
    if (!bot) {
      setError('Сначала создайте бота');
      return;
    }

    setTesting(true);
    setError(null);
    setSuccess(null);

    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${bot.id}/test-webhook`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      const data = await response.json();

      if (response.ok) {
        setSuccess('Webhook успешно протестирован! Проверьте бота в Telegram.');
      } else {
        setError(data.message || 'Ошибка при тестировании webhook');
      }
    } catch (err) {
      setError('Ошибка подключения к серверу');
    } finally {
      setTesting(false);
    }
  };

  const handleSaveWelcomeMessage = async () => {
    if (!bot) return;

    setSavingWelcome(true);
    setWelcomeError(null);
    setWelcomeSuccess(null);

    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${bot.id}/settings`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          welcome_message: welcomeMessage || null,
        }),
      });

      const data = await response.json();

      if (response.ok) {
        setWelcomeSuccess('Приветственное сообщение сохранено!');
        if (onUpdate) onUpdate();
      } else {
        setWelcomeError(data.message || 'Ошибка при сохранении');
      }
    } catch (err) {
      setWelcomeError('Ошибка подключения к серверу');
    } finally {
      setSavingWelcome(false);
    }
  };

  const handleResetWelcomeMessage = () => {
    setWelcomeMessage('');
    setWelcomeSuccess(null);
    setWelcomeError(null);
  };

  const handleSaveDescription = async () => {
    if (!bot) return;

    setSavingDescription(true);
    setDescriptionError(null);
    setDescriptionSuccess(null);

    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${bot.id}/description`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          description: botDescription,
          short_description: botShortDescription,
        }),
      });

      const data = await response.json();

      if (response.ok) {
        setDescriptionSuccess(data.message || 'Описание бота обновлено!');
      } else {
        setDescriptionError(data.message || data.error || 'Ошибка при сохранении');
      }
    } catch (err) {
      setDescriptionError('Ошибка подключения к серверу');
    } finally {
      setSavingDescription(false);
    }
  };

  if (bot) {
    return (
      <div className="bg-white rounded-lg shadow-md p-6 animate-fade-in">
        <h2 className="text-2xl font-bold text-gray-800 mb-6">Настройки бота</h2>

        {error && (
          <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 animate-slide-in">
            {error}
          </div>
        )}

        {success && (
          <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 animate-slide-in">
            {success}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-6">
          <div>
            <label htmlFor="token" className="block text-sm font-medium text-gray-700 mb-2">
              Token бота
            </label>
            <input
              type="text"
              id="token"
              name="token"
              value={formData.token}
              onChange={handleChange}
              required
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
              placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz"
            />
            <p className="mt-1 text-sm text-gray-500">
              Получите токен у @BotFather в Telegram
            </p>
          </div>


          <div className="flex space-x-4">
            <button
              type="submit"
              disabled={loading}
              className="flex-1 bg-blue-500 text-white px-6 py-3 rounded-lg font-medium hover:bg-blue-600 transition-colors duration-200 disabled:bg-gray-400 disabled:cursor-not-allowed"
            >
              {loading ? 'Сохранение...' : 'Обновить бота'}
            </button>

            <button
              type="button"
              onClick={handleTestWebhook}
              disabled={testing || !bot}
              className="flex-1 bg-green-500 text-white px-6 py-3 rounded-lg font-medium hover:bg-green-600 transition-colors duration-200 disabled:bg-gray-400 disabled:cursor-not-allowed"
            >
              {testing ? 'Тестирование...' : 'Тест Webhook'}
            </button>
          </div>
        </form>

        <div className="mt-6 p-4 bg-gray-50 rounded-lg">
          <h3 className="font-semibold text-gray-800 mb-2">Информация о боте</h3>
          <div className="space-y-2 text-sm text-gray-600">
            <p><strong>ID:</strong> {bot.id}</p>
            <p><strong>Создан:</strong> {new Date(bot.created_at).toLocaleString('ru-RU')}</p>
            <p><strong>Обновлен:</strong> {new Date(bot.updated_at).toLocaleString('ru-RU')}</p>
          </div>
        </div>

        {/* Блок редактирования приветственного сообщения */}
        <div className="mt-6 p-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-100">
          <h3 className="text-xl font-semibold text-gray-800 mb-4 flex items-center">
            <span className="text-2xl mr-2">💬</span>
            Приветственное сообщение
          </h3>
          <p className="text-sm text-gray-600 mb-4">
            Это сообщение отправляется пользователю при запуске команды <code className="bg-gray-200 px-1 rounded">/start</code>
          </p>

          {welcomeError && (
            <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 animate-slide-in">
              {welcomeError}
            </div>
          )}

          {welcomeSuccess && (
            <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 animate-slide-in">
              {welcomeSuccess}
            </div>
          )}

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Текст сообщения
              </label>
              <textarea
                value={welcomeMessage}
                onChange={(e) => {
                  setWelcomeMessage(e.target.value);
                  setWelcomeSuccess(null);
                  setWelcomeError(null);
                }}
                rows={6}
                className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 resize-none"
                placeholder={defaultWelcomeMessage || "Введите приветственное сообщение..."}
              />
              <p className="mt-1 text-sm text-gray-500">
                Оставьте пустым для использования сообщения по умолчанию
              </p>
            </div>

            {/* Превью дефолтного сообщения */}
            {!welcomeMessage && defaultWelcomeMessage && (
              <div className="bg-white p-4 rounded-lg border border-gray-200">
                <p className="text-xs text-gray-500 mb-2 uppercase tracking-wide">Текущее сообщение (по умолчанию):</p>
                <div className="text-gray-700 whitespace-pre-wrap text-sm">
                  {defaultWelcomeMessage}
                </div>
              </div>
            )}

            {/* Превью кастомного сообщения */}
            {welcomeMessage && (
              <div className="bg-white p-4 rounded-lg border border-green-200">
                <p className="text-xs text-green-600 mb-2 uppercase tracking-wide">Превью вашего сообщения:</p>
                <div className="text-gray-700 whitespace-pre-wrap text-sm">
                  {welcomeMessage}
                </div>
              </div>
            )}

            <div className="flex space-x-3">
              <button
                type="button"
                onClick={handleSaveWelcomeMessage}
                disabled={savingWelcome}
                className="flex-1 bg-blue-500 text-white px-6 py-3 rounded-lg font-medium hover:bg-blue-600 transition-colors duration-200 disabled:bg-gray-400 disabled:cursor-not-allowed"
              >
                {savingWelcome ? 'Сохранение...' : '💾 Сохранить сообщение'}
              </button>
              
              {welcomeMessage && (
                <button
                  type="button"
                  onClick={handleResetWelcomeMessage}
                  className="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-medium hover:bg-gray-300 transition-colors duration-200"
                >
                  🔄 Сбросить
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Блок редактирования описания бота (Bot Description) */}
        <div className="mt-6 p-6 bg-gradient-to-r from-purple-50 to-pink-50 rounded-lg border border-purple-100">
          <h3 className="text-xl font-semibold text-gray-800 mb-4 flex items-center">
            <span className="text-2xl mr-2">📋</span>
            Описание бота (Bot Description)
          </h3>
          <p className="text-sm text-gray-600 mb-4">
            Это описание отображается в окне чата <strong>до нажатия кнопки "Старт"</strong> (как на скриншоте Abuse® Club)
          </p>

          {loadingDescription && (
            <div className="flex items-center justify-center py-4">
              <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-purple-500"></div>
              <span className="ml-2 text-gray-600">Загрузка...</span>
            </div>
          )}

          {descriptionError && (
            <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 animate-slide-in">
              {descriptionError}
            </div>
          )}

          {descriptionSuccess && (
            <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 animate-slide-in">
              {descriptionSuccess}
            </div>
          )}

          {!loadingDescription && (
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Описание бота (до 512 символов)
                </label>
                <textarea
                  value={botDescription}
                  onChange={(e) => {
                    setBotDescription(e.target.value);
                    setDescriptionSuccess(null);
                    setDescriptionError(null);
                  }}
                  rows={6}
                  maxLength={512}
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200 resize-none"
                  placeholder="Что может делать этот бот?&#10;&#10;Опишите возможности вашего бота..."
                />
                <p className="mt-1 text-sm text-gray-500">
                  {botDescription.length}/512 символов. Показывается в пустом чате до нажатия "Старт"
                </p>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Краткое описание (до 120 символов)
                </label>
                <input
                  type="text"
                  value={botShortDescription}
                  onChange={(e) => {
                    setBotShortDescription(e.target.value);
                    setDescriptionSuccess(null);
                    setDescriptionError(null);
                  }}
                  maxLength={120}
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all duration-200"
                  placeholder="Краткое описание для профиля бота"
                />
                <p className="mt-1 text-sm text-gray-500">
                  {botShortDescription.length}/120 символов. Показывается в профиле бота
                </p>
              </div>

              {/* Превью описания */}
              {botDescription && (
                <div className="bg-white p-4 rounded-lg border border-purple-200">
                  <p className="text-xs text-purple-600 mb-2 uppercase tracking-wide flex items-center">
                    <span className="mr-1">👁️</span> Превью описания:
                  </p>
                  <div className="bg-gray-100 p-3 rounded-lg">
                    <p className="text-sm font-semibold text-gray-800 mb-2">Что может делать этот бот?</p>
                    <div className="text-gray-700 whitespace-pre-wrap text-sm">
                      {botDescription}
                    </div>
                  </div>
                </div>
              )}

              <button
                type="button"
                onClick={handleSaveDescription}
                disabled={savingDescription}
                className="w-full bg-purple-500 text-white px-6 py-3 rounded-lg font-medium hover:bg-purple-600 transition-colors duration-200 disabled:bg-gray-400 disabled:cursor-not-allowed"
              >
                {savingDescription ? 'Сохранение...' : '📋 Сохранить описание в Telegram'}
              </button>

              <p className="text-xs text-gray-500 text-center">
                Описание сохраняется напрямую в Telegram через Bot API
              </p>
            </div>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-lg shadow-md p-6 animate-fade-in">
      <h2 className="text-2xl font-bold text-gray-800 mb-6">Создать Telegram бота</h2>
      <p className="text-gray-600 mb-6">
        Вы можете создать только одного бота. После создания webhook будет автоматически зарегистрирован.
      </p>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 animate-slide-in">
          {error}
        </div>
      )}

      {success && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 animate-slide-in">
          {success}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-6">
        <div>
          <label htmlFor="token" className="block text-sm font-medium text-gray-700 mb-2">
            Token бота *
          </label>
          <input
            type="text"
            id="token"
            name="token"
            value={formData.token}
            onChange={handleChange}
            required
            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
            placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz"
          />
          <p className="mt-1 text-sm text-gray-500">
            Получите токен у @BotFather в Telegram
          </p>
        </div>

        <div className="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-lg">
          <p className="text-sm">
            <strong>Webhook URL будет автоматически сгенерирован:</strong> {getDefaultWebhookUrl()}
          </p>
        </div>

        <button
          type="submit"
          disabled={loading}
          className="w-full bg-blue-500 text-white px-6 py-3 rounded-lg font-medium hover:bg-blue-600 transition-colors duration-200 disabled:bg-gray-400 disabled:cursor-not-allowed"
        >
          {loading ? 'Создание...' : 'Создать бота'}
        </button>
      </form>
    </div>
  );
}

export default BotSettings;
