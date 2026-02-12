import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function RaffleSettings({ bot }) {
  const [settings, setSettings] = useState(null);
  const [currentRaffle, setCurrentRaffle] = useState(null);
  const [activeRaffleMissing, setActiveRaffleMissing] = useState(false);
  const [ticketsStats, setTicketsStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(null);
  const [activeTab, setActiveTab] = useState('general');

  const [formData, setFormData] = useState({
    total_slots: 500,
    slot_price: 10000,
    slots_mode: 'sequential',
    is_active: true,
    receipt_parser_method: 'legacy',
    payment_description: 'За наклейку',
    support_contact: '',
    raffle_info: '',
    prize_description: '',
  });

  const [messages, setMessages] = useState({});

  useEffect(() => {
    if (bot) {
      fetchSettings();
    }
  }, [bot]);

  const fetchSettings = async () => {
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${bot.id}/raffle-settings`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      const data = await response.json().catch(() => ({}));
      if (response.ok) {
        setSettings(data.settings);
        setCurrentRaffle(data.current_raffle || null);
        setActiveRaffleMissing(!!data.active_raffle_missing);
        setTicketsStats(data.tickets_stats);

        // Заполняем форму
        setFormData({
          total_slots: data.settings.total_slots,
          slot_price: data.settings.slot_price,
          slots_mode: data.settings.slots_mode,
          is_active: data.settings.is_active,
          receipt_parser_method: data.settings.receipt_parser_method || 'legacy',
          payment_description: data.settings.payment_description || '',
          support_contact: data.settings.support_contact || '',
          raffle_info: data.settings.raffle_info || '',
          prize_description: data.settings.prize_description || '',
        });

        // Заполняем сообщения
        const msgs = {};
        Object.keys(data.default_messages || {}).forEach(key => {
          const fieldName = key.replace('msg_', '');
          msgs[fieldName] = data.settings[key] || '';
        });
        setMessages(msgs);
        setError(null);
      } else {
        setError(data.message || 'Ошибка загрузки настроек');
      }
    } catch (err) {
      console.error('Error fetching settings:', err);
      setError('Ошибка загрузки настроек');
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async () => {
    setSaving(true);
    setError(null);
    setSuccess(null);

    try {
      const token = localStorage.getItem('token');
      
      // Подготавливаем данные
      const dataToSend = { ...formData };
      
      // Добавляем сообщения
      Object.keys(messages).forEach(key => {
        if (messages[key]) {
          dataToSend[`msg_${key}`] = messages[key];
        }
      });

      const response = await fetch(`${API_URL}/api/bot/${bot.id}/raffle-settings`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(dataToSend),
      });

      const data = await response.json();

      if (response.ok) {
        setSuccess('Настройки сохранены!');
        setSettings(data.settings);
        if (data.current_raffle) setCurrentRaffle(data.current_raffle);
        setTicketsStats(data.tickets_stats);
      } else {
        setError(data.message || 'Ошибка сохранения');
      }
    } catch (err) {
      setError('Ошибка подключения');
    } finally {
      setSaving(false);
    }
  };

  const handleUploadQr = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const formDataUpload = new FormData();
    formDataUpload.append('qr_image', file);

    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${bot.id}/raffle-settings/upload-qr`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
        },
        body: formDataUpload,
      });

      const data = await response.json();

      if (response.ok) {
        setSuccess('QR-код загружен!');
        setSettings(prev => (prev ? { ...prev, qr_image_path: data.qr_image_path } : prev));
        fetchSettings();
      } else {
        setError(data.message || 'Ошибка загрузки QR-кода');
      }
    } catch (err) {
      setError('Ошибка загрузки');
    }
  };

  const handleInitializeTickets = async () => {
    if (!window.confirm(`Инициализировать ${formData.total_slots} номерков?`)) return;

    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/bot/${bot.id}/raffle-settings/initialize-tickets`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      const data = await response.json();

      if (response.ok) {
        setSuccess('Номерки инициализированы!');
        setTicketsStats(data.tickets_stats);
      } else {
        setError(data.message || 'Ошибка инициализации');
      }
    } catch (err) {
      setError('Ошибка подключения');
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-32">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-purple-500"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Активный розыгрыш */}
      {currentRaffle && (
        <div className="bg-indigo-50 border border-indigo-200 rounded-lg px-4 py-3 text-indigo-800">
          <p className="font-medium">
            Настройки для активного розыгрыша: <strong>{currentRaffle.name || `Розыгрыш #${currentRaffle.id}`}</strong> (ID: {currentRaffle.id})
          </p>
          <p className="text-sm mt-1 opacity-90">
            Активный розыгрыш выбирается на странице <Link to="/raffles" className="underline font-medium">Розыгрыши</Link>. Телеграм-бот работает только с активным розыгрышем.
          </p>
        </div>
      )}

      {/* Нет активного розыгрыша — блокируем сохранение */}
      {activeRaffleMissing && (
        <div className="bg-amber-50 border border-amber-300 rounded-lg px-4 py-3 text-amber-800">
          <p className="font-medium">⚠️ Нет активного розыгрыша</p>
          <p className="text-sm mt-1">
            Создайте или активируйте розыгрыш на странице <Link to="/raffles" className="underline font-medium">Розыгрыши</Link>, затем здесь можно будет сохранять настройки (в т.ч. QR-код и сообщения).
          </p>
        </div>
      )}

      {/* Заголовок */}
      <div className="flex justify-between items-center">
        <h2 className="text-2xl font-bold text-gray-800">🎯 Настройки розыгрыша</h2>
        <button
          onClick={handleSave}
          disabled={saving || activeRaffleMissing}
          className="px-6 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 disabled:opacity-50 transition-colors"
          title={activeRaffleMissing ? 'Сначала активируйте розыгрыш на странице Розыгрыши' : ''}
        >
          {saving ? 'Сохранение...' : '💾 Сохранить всё'}
        </button>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
          {error}
        </div>
      )}

      {success && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
          {success}
        </div>
      )}

      {/* Статистика номерков */}
      {ticketsStats && (
        <div className="grid grid-cols-4 gap-4">
          <div className="bg-gradient-to-r from-purple-500 to-purple-600 p-4 rounded-lg text-white">
            <div className="text-2xl font-bold">{ticketsStats.total}</div>
            <div className="text-sm opacity-80">Всего номерков</div>
          </div>
          <div className="bg-gradient-to-r from-green-500 to-green-600 p-4 rounded-lg text-white">
            <div className="text-2xl font-bold">{ticketsStats.issued}</div>
            <div className="text-sm opacity-80">Выдано</div>
          </div>
          <div className="bg-gradient-to-r from-blue-500 to-blue-600 p-4 rounded-lg text-white">
            <div className="text-2xl font-bold">{ticketsStats.available}</div>
            <div className="text-sm opacity-80">Свободно</div>
          </div>
          <div className="bg-gradient-to-r from-orange-500 to-orange-600 p-4 rounded-lg text-white">
            <div className="text-2xl font-bold">{ticketsStats.percentage_issued}%</div>
            <div className="text-sm opacity-80">Заполнено</div>
          </div>
        </div>
      )}

      {/* Табы */}
      <div className="flex gap-2 border-b">
        <button
          onClick={() => setActiveTab('general')}
          className={`px-4 py-2 font-medium transition-colors ${
            activeTab === 'general'
              ? 'text-purple-600 border-b-2 border-purple-600'
              : 'text-gray-500 hover:text-gray-700'
          }`}
        >
          ⚙️ Основные
        </button>
        <button
          onClick={() => setActiveTab('qr')}
          className={`px-4 py-2 font-medium transition-colors ${
            activeTab === 'qr'
              ? 'text-purple-600 border-b-2 border-purple-600'
              : 'text-gray-500 hover:text-gray-700'
          }`}
        >
          📱 QR-код
        </button>
        <button
          onClick={() => setActiveTab('messages')}
          className={`px-4 py-2 font-medium transition-colors ${
            activeTab === 'messages'
              ? 'text-purple-600 border-b-2 border-purple-600'
              : 'text-gray-500 hover:text-gray-700'
          }`}
        >
          💬 Сообщения
        </button>
      </div>

      {/* Основные настройки */}
      {activeTab === 'general' && (
        <div className="bg-white p-6 rounded-lg shadow-md space-y-6">
          <div className="grid grid-cols-2 gap-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Количество мест (номерков)
              </label>
              <input
                type="number"
                value={formData.total_slots}
                onChange={(e) => setFormData({ ...formData, total_slots: parseInt(e.target.value) || 0 })}
                className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Стоимость одного места (₽)
              </label>
              <input
                type="number"
                value={formData.slot_price}
                onChange={(e) => setFormData({ ...formData, slot_price: parseFloat(e.target.value) || 0 })}
                className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Режим выдачи номерков
              </label>
              <select
                value={formData.slots_mode}
                onChange={(e) => setFormData({ ...formData, slots_mode: e.target.value })}
                className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500"
              >
                <option value="sequential">Последовательно (1, 2, 3...)</option>
                <option value="random">Случайно</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Назначение платежа
              </label>
              <input
                type="text"
                value={formData.payment_description}
                onChange={(e) => setFormData({ ...formData, payment_description: e.target.value })}
                className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500"
              />
            </div>
          </div>

          <div className="flex items-center gap-3">
            <input
              type="checkbox"
              id="is_active"
              checked={formData.is_active}
              onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
              className="w-5 h-5 rounded text-purple-600 focus:ring-purple-500"
            />
            <label htmlFor="is_active" className="text-gray-700">
              Розыгрыш активен (принимаются платежи)
            </label>
          </div>

          <div className="grid grid-cols-2 gap-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Метод определения суммы и даты из чеков
              </label>
              <select
                value={formData.receipt_parser_method || 'legacy'}
                onChange={(e) => setFormData({ ...formData, receipt_parser_method: e.target.value })}
                className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500"
              >
                <option value="legacy">Классический (текущий)</option>
                <option value="enhanced">Улучшенный (pdftotext, контекст даты, оплачено/списано, уверенность)</option>
                <option value="enhanced_ai">Интеллектуальный (улучшенный + AI при низкой уверенности)</option>
              </select>
              <p className="mt-1 text-xs text-gray-500">
                {formData.receipt_parser_method === 'enhanced_ai'
                  ? 'Улучшенный парсер + LLM как fallback при низкой уверенности или отсутствии суммы/даты. Требует RECEIPT_AI_ENABLED и OPENAI_API_KEY.'
                  : 'Улучшенный: текстовый PDF без OCR, выбор даты по контексту, маркеры «оплачено»/«списано», оценка уверенности.'}
              </p>
            </div>
          </div>

          {/* Информация о розыгрыше */}
          <div className="pt-4 border-t">
            <h4 className="font-medium text-gray-800 mb-4">📢 Информация для пользователей</h4>
            
            <div className="grid grid-cols-2 gap-6 mb-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Описание приза
                </label>
                <input
                  type="text"
                  value={formData.prize_description}
                  onChange={(e) => setFormData({ ...formData, prize_description: e.target.value })}
                  placeholder="Например: iPhone 15 Pro"
                  className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Контакт поддержки
                </label>
                <input
                  type="text"
                  value={formData.support_contact}
                  onChange={(e) => setFormData({ ...formData, support_contact: e.target.value })}
                  placeholder="@support или https://t.me/support"
                  className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500"
                />
              </div>
            </div>
            
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Дополнительная информация о розыгрыше
              </label>
              <textarea
                value={formData.raffle_info}
                onChange={(e) => setFormData({ ...formData, raffle_info: e.target.value })}
                placeholder="Условия участия, правила розыгрыша..."
                rows={3}
                className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500 resize-none"
              />
            </div>
          </div>

          <div className="pt-4 border-t">
            <button
              onClick={handleInitializeTickets}
              className="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors"
            >
              🔄 Инициализировать номерки
            </button>
            <p className="text-sm text-gray-500 mt-2">
              Создаёт номерки от 1 до {formData.total_slots}. Уже существующие номерки не затрагиваются.
            </p>
          </div>
        </div>
      )}

      {/* QR-код */}
      {activeTab === 'qr' && (
        <div className="bg-white p-6 rounded-lg shadow-md">
          <h3 className="text-lg font-semibold text-gray-800 mb-4">📱 QR-код для оплаты</h3>
          
          <div className="grid grid-cols-2 gap-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Загрузить новый QR-код
              </label>
              <input
                type="file"
                accept="image/*"
                onChange={handleUploadQr}
                className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500"
              />
              <p className="text-sm text-gray-500 mt-2">
                Поддерживаются: JPG, PNG. Макс. размер: 5 MB
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Текущий QR-код
              </label>
              {settings?.qr_image_path ? (
                <img
                  src={`${API_URL}/storage/${settings.qr_image_path}`}
                  alt="QR-код"
                  className="max-w-full h-48 object-contain border rounded-lg"
                />
              ) : (
                <div className="h-48 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400">
                  QR-код не загружен
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Сообщения */}
      {activeTab === 'messages' && (
        <div className="bg-white p-6 rounded-lg shadow-md space-y-6">
          <h3 className="text-lg font-semibold text-gray-800">💬 Настройка сообщений бота</h3>
          <p className="text-sm text-gray-500">
            Оставьте поле пустым для использования сообщения по умолчанию.
            Переменные: {'{price}'}, {'{available_slots}'}, {'{total_slots}'}, {'{fio}'}, {'{phone}'}, {'{tickets}'}, {'{reason}'}, {'{status_info}'}
          </p>

          <div className="space-y-4">
            <h4 className="font-medium text-gray-800 mb-2">🎯 Сценарий участия</h4>
            {[
              { key: 'welcome', label: 'Приветствие (места есть)', placeholder: 'Добро пожаловать в розыгрыш...' },
              { key: 'no_slots', label: 'Нет мест', placeholder: 'К сожалению, все места заняты...' },
              { key: 'ask_fio', label: 'Запрос ФИО', placeholder: 'Введите ваше ФИО...' },
              { key: 'ask_phone', label: 'Запрос телефона', placeholder: 'Введите номер телефона...' },
              { key: 'confirm_data', label: 'Подтверждение данных', placeholder: 'Проверьте данные: {fio}, {phone}' },
              { key: 'show_qr', label: 'Показ QR-кода', placeholder: 'Оплатите {price} ₽...' },
              { key: 'wait_check', label: 'Ожидание чека', placeholder: 'Отправьте чек...' },
              { key: 'check_received', label: 'Чек получен', placeholder: 'Чек отправлен на проверку...' },
              { key: 'check_approved', label: 'Чек одобрен', placeholder: 'Платёж подтверждён! Ваши номерки: {tickets}' },
              { key: 'check_rejected', label: 'Чек отклонён', placeholder: 'Чек не принят. {reason}' },
              { key: 'check_duplicate', label: '⚠️ Дубликат чека', placeholder: 'Этот чек уже был использован! {status_info}' },
            ].map(({ key, label, placeholder }) => (
              <div key={key}>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  {label}
                </label>
                <textarea
                  value={messages[key] || ''}
                  onChange={(e) => setMessages({ ...messages, [key]: e.target.value })}
                  placeholder={placeholder}
                  rows={3}
                  className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500 resize-none"
                />
              </div>
            ))}

            <h4 className="font-medium text-gray-800 mt-6 mb-2 pt-4 border-t">📱 Постоянное меню</h4>
            <p className="text-sm text-gray-500 mb-4">
              Эти сообщения отображаются при нажатии кнопок постоянного меню.
              Переменные: {'{prize}'}, {'{price}'}, {'{total_slots}'}, {'{available_slots}'}, {'{raffle_info}'}, {'{tickets}'}, {'{count}'}, {'{support_contact}'}
            </p>
            {[
              { key: 'about_raffle', label: 'ℹ️ О розыгрыше', placeholder: 'Информация о розыгрыше: {prize}, {price} ₽...' },
              { key: 'my_tickets', label: '🎫 Мои номерки (есть номерки)', placeholder: 'Ваши номерки: {tickets}. Всего: {count} шт.' },
              { key: 'no_tickets', label: '🎫 Мои номерки (нет номерков)', placeholder: 'У вас пока нет номерков...' },
              { key: 'support', label: '💬 Поддержка', placeholder: 'По всем вопросам: {support_contact}' },
            ].map(({ key, label, placeholder }) => (
              <div key={key}>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  {label}
                </label>
                <textarea
                  value={messages[key] || ''}
                  onChange={(e) => setMessages({ ...messages, [key]: e.target.value })}
                  placeholder={placeholder}
                  rows={3}
                  className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500 resize-none"
                />
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

export default RaffleSettings;
