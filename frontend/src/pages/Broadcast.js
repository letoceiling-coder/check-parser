import React, { useState, useEffect, useCallback } from 'react';
import Swal from 'sweetalert2';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

const TYPES = [
  { value: 'text', label: 'Только сообщение', needFile: false },
  { value: 'photo', label: 'Только фото', needFile: true, accept: 'image/*' },
  { value: 'photo_text', label: 'Фото + сообщение', needFile: true, accept: 'image/*' },
  { value: 'video', label: 'Только видео', needFile: true, accept: 'video/*' },
  { value: 'video_text', label: 'Видео + сообщение', needFile: true, accept: 'video/*' },
];

function Broadcast() {
  const [botId, setBotId] = useState(null);
  const [type, setType] = useState('text');
  const [messageText, setMessageText] = useState('');
  const [recipientsType, setRecipientsType] = useState('all');
  const [recipientIds, setRecipientIds] = useState([]);
  const [file, setFile] = useState(null);
  const [users, setUsers] = useState([]);
  const [loadingUsers, setLoadingUsers] = useState(false);
  const [sending, setSending] = useState(false);
  const [history, setHistory] = useState([]);
  const [loadingHistory, setLoadingHistory] = useState(true);

  const fetchBot = useCallback(async () => {
    const token = localStorage.getItem('token');
    const res = await fetch(`${API_URL}/api/bot`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (res.ok) {
      const data = await res.json();
      const bot = Array.isArray(data) ? data[0] : data;
      if (bot) setBotId(bot.id);
    }
  }, []);

  const fetchUsers = useCallback(async () => {
    if (!botId) return;
    setLoadingUsers(true);
    try {
      const token = localStorage.getItem('token');
      const res = await fetch(`${API_URL}/api/bot-users?per_page=500`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      if (res.ok) {
        const data = await res.json();
        setUsers(data.data || data);
      }
    } finally {
      setLoadingUsers(false);
    }
  }, [botId]);

  const fetchHistory = useCallback(async () => {
    setLoadingHistory(true);
    try {
      const token = localStorage.getItem('token');
      const res = await fetch(`${API_URL}/api/broadcasts?per_page=15`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      if (res.ok) {
        const data = await res.json();
        setHistory(data.data || []);
      }
    } finally {
      setLoadingHistory(false);
    }
  }, []);

  useEffect(() => {
    fetchBot();
  }, [fetchBot]);

  useEffect(() => {
    if (botId) {
      fetchUsers();
      fetchHistory();
    }
  }, [botId, fetchUsers, fetchHistory]);

  const currentType = TYPES.find((t) => t.value === type) || TYPES[0];
  const needFile = currentType.needFile;

  const toggleUser = (id) => {
    setRecipientIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  };

  const toggleAllUsers = () => {
    if (recipientIds.length === users.length) {
      setRecipientIds([]);
    } else {
      setRecipientIds(users.map((u) => u.id));
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!botId) {
      Swal.fire({ icon: 'warning', title: 'Нет бота', text: 'Сначала создайте бота в настройках.' });
      return;
    }
    if (needFile && !file) {
      Swal.fire({ icon: 'warning', title: 'Выберите файл', text: 'Загрузите фото или видео.' });
      return;
    }
    if (type === 'text' && !messageText.trim()) {
      Swal.fire({ icon: 'warning', title: 'Введите текст', text: 'Добавьте текст сообщения.' });
      return;
    }
    if (recipientsType === 'selected' && recipientIds.length === 0) {
      Swal.fire({ icon: 'warning', title: 'Выберите получателей', text: 'Отметьте хотя бы одного пользователя.' });
      return;
    }

    setSending(true);
    try {
      const formData = new FormData();
      formData.append('type', type);
      formData.append('message_text', messageText);
      formData.append('recipients_type', recipientsType);
      if (recipientsType === 'selected') {
        recipientIds.forEach((id) => formData.append('recipient_ids[]', id));
      }
      if (file) formData.append('file', file);

      const token = localStorage.getItem('token');
      const res = await fetch(`${API_URL}/api/broadcasts`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
        body: formData,
      });

      const data = await res.json().catch(() => ({}));

      if (res.ok && data.broadcast) {
        const b = data.broadcast;
        await Swal.fire({
          icon: b.failed_count > 0 && b.success_count === 0 ? 'error' : 'success',
          title: 'Рассылка завершена',
          html:
            `<p><strong>Тип:</strong> ${b.type_label}</p>` +
            `<p><strong>Получателей:</strong> ${b.recipients_count}</p>` +
            `<p><strong>Доставлено:</strong> ${b.success_count}</p>` +
            (b.failed_count > 0 ? `<p><strong>Ошибок:</strong> ${b.failed_count}</p>` : ''),
        });
        setMessageText('');
        setFile(null);
        setRecipientIds([]);
        fetchHistory();
      } else {
        await Swal.fire({
          icon: 'error',
          title: 'Ошибка',
          text: data.message || 'Не удалось выполнить рассылку.',
        });
      }
    } catch (err) {
      await Swal.fire({
        icon: 'error',
        title: 'Ошибка',
        text: 'Ошибка сети или сервера.',
      });
    } finally {
      setSending(false);
    }
  };

  const formatDate = (str) => {
    if (!str) return '—';
    const d = new Date(str);
    return d.toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  if (!botId) {
    return (
      <div className="p-6">
        <div className="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-6">
          <p className="font-medium">Сначала создайте бота</p>
          <p className="text-sm mt-1">Раздел «Настройки бота» → создайте бота, после этого рассылка будет доступна.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-8">
      <div>
        <h1 className="text-2xl font-bold text-gray-800">📢 Рассылка</h1>
        <p className="text-gray-600">Отправка сообщений, фото или видео всем пользователям бота или выбранным.</p>
      </div>

      <form onSubmit={handleSubmit} className="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-100 bg-gray-50">
          <h2 className="text-lg font-semibold text-gray-800">Новая рассылка</h2>
        </div>
        <div className="p-6 space-y-6">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Тип рассылки</label>
            <div className="flex flex-wrap gap-3">
              {TYPES.map((t) => (
                <label
                  key={t.value}
                  className={`inline-flex items-center px-4 py-2 rounded-lg border cursor-pointer transition-colors ${
                    type === t.value
                      ? 'border-blue-500 bg-blue-50 text-blue-700'
                      : 'border-gray-200 hover:bg-gray-50'
                  }`}
                >
                  <input
                    type="radio"
                    name="type"
                    value={t.value}
                    checked={type === t.value}
                    onChange={() => { setType(t.value); setFile(null); }}
                    className="sr-only"
                  />
                  {t.label}
                </label>
              ))}
            </div>
          </div>

          {(type === 'text' || type === 'photo_text' || type === 'video_text') && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Текст сообщения</label>
              <textarea
                value={messageText}
                onChange={(e) => setMessageText(e.target.value)}
                rows={4}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder="Поддерживается HTML-разметка для Telegram"
              />
            </div>
          )}

          {needFile && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                {type.includes('photo') ? 'Фото' : 'Видео'} (обязательно)
              </label>
              <input
                type="file"
                accept={currentType.accept}
                onChange={(e) => setFile(e.target.files?.[0] || null)}
                className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700"
              />
              {file && <p className="mt-1 text-sm text-gray-500">{file.name}</p>}
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Получатели</label>
            <div className="flex gap-6">
              <label className="inline-flex items-center gap-2 cursor-pointer">
                <input
                  type="radio"
                  name="recipients"
                  checked={recipientsType === 'all'}
                  onChange={() => setRecipientsType('all')}
                  className="text-blue-600"
                />
                Всем пользователям бота
              </label>
              <label className="inline-flex items-center gap-2 cursor-pointer">
                <input
                  type="radio"
                  name="recipients"
                  checked={recipientsType === 'selected'}
                  onChange={() => setRecipientsType('selected')}
                  className="text-blue-600"
                />
                Выбранным
              </label>
            </div>

            {recipientsType === 'selected' && (
              <div className="mt-4 p-4 bg-gray-50 rounded-lg border border-gray-200 max-h-64 overflow-y-auto">
                {loadingUsers ? (
                  <p className="text-gray-500">Загрузка списка...</p>
                ) : (
                  <>
                    <button
                      type="button"
                      onClick={toggleAllUsers}
                      className="text-sm text-blue-600 hover:underline mb-2"
                    >
                      {recipientIds.length === users.length ? 'Снять всех' : 'Выбрать всех'}
                    </button>
                    <ul className="space-y-1">
                      {users.map((u) => (
                        <li key={u.id}>
                          <label className="flex items-center gap-2 cursor-pointer hover:bg-white/50 rounded px-2 py-1">
                            <input
                              type="checkbox"
                              checked={recipientIds.includes(u.id)}
                              onChange={() => toggleUser(u.id)}
                              className="rounded text-blue-600"
                            />
                            <span className="text-sm">
                              {u.display_name || u.first_name || `@${u.username}` || `#${u.telegram_user_id}`}
                            </span>
                          </label>
                        </li>
                      ))}
                    </ul>
                    {recipientIds.length > 0 && (
                      <p className="text-sm text-gray-500 mt-2">Выбрано: {recipientIds.length}</p>
                    )}
                  </>
                )}
              </div>
            )}
          </div>

          <div className="pt-4">
            <button
              type="submit"
              disabled={sending}
              className="px-6 py-3 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {sending ? 'Отправка...' : '📤 Отправить рассылку'}
            </button>
          </div>
        </div>
      </form>

      <div className="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-100 bg-gray-50">
          <h2 className="text-lg font-semibold text-gray-800">История рассылок</h2>
        </div>
        <div className="overflow-x-auto">
          {loadingHistory ? (
            <div className="p-8 text-center text-gray-500">Загрузка...</div>
          ) : history.length === 0 ? (
            <div className="p-8 text-center text-gray-500">Пока нет рассылок</div>
          ) : (
            <table className="w-full">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Дата</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Тип</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Получателей</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Доставлено</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Ошибок</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {history.map((b) => (
                  <tr key={b.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm text-gray-600">{formatDate(b.created_at)}</td>
                    <td className="px-4 py-3 text-sm font-medium text-gray-800">{b.type_label}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{b.recipients_count}</td>
                    <td className="px-4 py-3 text-sm text-green-600">{b.success_count}</td>
                    <td className="px-4 py-3 text-sm text-red-600">{b.failed_count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}

export default Broadcast;
