import React, { useState, useEffect } from 'react';

const API_URL = process.env.REACT_APP_API_URL || window.location.origin;

function GoogleSheetsSettings() {
  const [settings, setSettings] = useState({
    enabled: false,
    credentialsPath: null,
    hasCredentials: false,
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(null);
  
  // Состояние для загрузки файла
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState(null);
  const [uploadSuccess, setUploadSuccess] = useState(null);
  
  // Состояние для тестирования
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState(null);
  
  // Состояние для аккордеона инструкций
  const [expandedStep, setExpandedStep] = useState(null);

  useEffect(() => {
    fetchSettings();
  }, []);

  const fetchSettings = async () => {
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/google-sheets/settings`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const data = await response.json();
        setSettings(data);
      } else {
        setError('Не удалось загрузить настройки');
      }
    } catch (err) {
      setError('Ошибка подключения к серверу');
    } finally {
      setLoading(false);
    }
  };

  const handleToggleEnabled = async () => {
    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/google-sheets/toggle`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ enabled: !settings.enabled }),
      });

      if (response.ok) {
        const data = await response.json();
        setSettings(prev => ({ ...prev, enabled: data.enabled }));
        setSuccess(data.enabled ? 'Интеграция включена' : 'Интеграция отключена');
        setTimeout(() => setSuccess(null), 3000);
      } else {
        const error = await response.json();
        setError(error.message || 'Ошибка изменения настроек');
      }
    } catch (err) {
      setError('Ошибка подключения к серверу');
    }
  };

  const handleFileUpload = async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    // Проверка типа файла
    if (!file.name.endsWith('.json')) {
      setUploadError('Файл должен быть в формате JSON');
      return;
    }

    setUploading(true);
    setUploadError(null);
    setUploadSuccess(null);

    const formData = new FormData();
    formData.append('credentials', file);

    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/google-sheets/upload-credentials`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
        body: formData,
      });

      if (response.ok) {
        const data = await response.json();
        setUploadSuccess('Файл ключа успешно загружен');
        setSettings(prev => ({ ...prev, hasCredentials: true, credentialsPath: data.path }));
        setTimeout(() => setUploadSuccess(null), 5000);
      } else {
        const error = await response.json();
        setUploadError(error.message || 'Ошибка загрузки файла');
      }
    } catch (err) {
      setUploadError('Ошибка подключения к серверу');
    } finally {
      setUploading(false);
    }
  };

  const handleTestConnection = async () => {
    setTesting(true);
    setTestResult(null);
    setError(null);

    try {
      const token = localStorage.getItem('token');
      const response = await fetch(`${API_URL}/api/google-sheets/test`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      const data = await response.json();
      setTestResult(data);
    } catch (err) {
      setTestResult({
        success: false,
        message: 'Ошибка подключения к серверу',
      });
    } finally {
      setTesting(false);
    }
  };

  const toggleStep = (step) => {
    setExpandedStep(expandedStep === step ? null : step);
  };

  const instructionSteps = [
    {
      id: 'step1',
      title: '1. Создать проект в Google Cloud Console',
      content: (
        <div className="space-y-3">
          <p>1. Перейдите на <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">Google Cloud Console</a></p>
          <p>2. Нажмите на выпадающий список проектов (вверху слева)</p>
          <p>3. Нажмите <strong>"Создать проект"</strong> (New Project)</p>
          <p>4. Введите название: <code className="bg-gray-100 px-2 py-1 rounded">lexauto-raffle-bot</code></p>
          <p>5. Нажмите <strong>"Создать"</strong> и дождитесь создания (30-60 секунд)</p>
        </div>
      ),
    },
    {
      id: 'step2',
      title: '2. Включить Google Sheets API',
      content: (
        <div className="space-y-3">
          <p>1. В левом меню: <strong>APIs & Services</strong> → <strong>Library</strong></p>
          <p>2. В поиске введите: <code className="bg-gray-100 px-2 py-1 rounded">Google Sheets API</code></p>
          <p>3. Кликните на результат поиска</p>
          <p>4. Нажмите <strong>"Enable"</strong> (Включить)</p>
          <p>5. Дождитесь активации (5-10 секунд)</p>
        </div>
      ),
    },
    {
      id: 'step3',
      title: '3. Создать Service Account',
      content: (
        <div className="space-y-3">
          <p>1. В левом меню: <strong>APIs & Services</strong> → <strong>Credentials</strong></p>
          <p>2. Нажмите <strong>"Create Credentials"</strong> → <strong>"Service account"</strong></p>
          <p>3. Заполните:</p>
          <ul className="list-disc list-inside ml-4 space-y-1">
            <li><strong>Service account name:</strong> <code className="bg-gray-100 px-2 py-1 rounded">lexauto-sheets-writer</code></li>
            <li><strong>Description:</strong> Service account for writing raffle data</li>
          </ul>
          <p>4. Нажмите <strong>"Create and Continue"</strong></p>
          <p>5. Роль: выберите <strong>Editor</strong> (или пропустите)</p>
          <p>6. Нажмите <strong>"Continue"</strong> и <strong>"Done"</strong></p>
        </div>
      ),
    },
    {
      id: 'step4',
      title: '4. Скачать JSON-ключ',
      content: (
        <div className="space-y-3">
          <p>1. В списке Service Accounts найдите созданный аккаунт</p>
          <p>2. Кликните на него</p>
          <p>3. Перейдите на вкладку <strong>"Keys"</strong></p>
          <p>4. Нажмите <strong>"Add Key"</strong> → <strong>"Create new key"</strong></p>
          <p>5. Выберите тип: <strong>JSON</strong></p>
          <p>6. Нажмите <strong>"Create"</strong></p>
          <p>7. Файл автоматически скачается (например: <code className="bg-gray-100 px-2 py-1 rounded">project-id-123456.json</code>)</p>
          <p className="text-red-600 font-semibold">⚠️ Этот файл содержит приватный ключ. Храните его в безопасности!</p>
        </div>
      ),
    },
    {
      id: 'step5',
      title: '5. Скопировать email Service Account',
      content: (
        <div className="space-y-3">
          <p>1. В скачанном JSON-файле найдите поле <code className="bg-gray-100 px-2 py-1 rounded">client_email</code></p>
          <p>2. Скопируйте значение, например:</p>
          <code className="block bg-gray-100 p-2 rounded text-sm">
            lexauto-sheets-writer@project-id-123456.iam.gserviceaccount.com
          </code>
          <p>3. Этот email понадобится на следующем шаге</p>
        </div>
      ),
    },
    {
      id: 'step6',
      title: '6. Создать Google Таблицу и дать доступ',
      content: (
        <div className="space-y-3">
          <p>1. Перейдите на <a href="https://sheets.google.com/" target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">Google Sheets</a></p>
          <p>2. Создайте новую таблицу: <strong>"Пустой файл"</strong></p>
          <p>3. Назовите её: <code className="bg-gray-100 px-2 py-1 rounded">LEXAUTO Розыгрыш - Участники</code></p>
          <p>4. Нажмите кнопку <strong>"Настройки доступа"</strong> (Share, справа вверху)</p>
          <p>5. Вставьте email Service Account (из шага 5)</p>
          <p>6. Выберите роль: <strong>"Редактор"</strong></p>
          <p>7. <strong>Снимите галочку</strong> "Отправить уведомления"</p>
          <p>8. Нажмите <strong>"Поделиться"</strong></p>
          <p>9. Скопируйте URL таблицы из адресной строки браузера</p>
        </div>
      ),
    },
    {
      id: 'step7',
      title: '7. Настроить в боте',
      content: (
        <div className="space-y-3">
          <p>1. Загрузите JSON-файл с ключом выше (раздел "Загрузка ключа")</p>
          <p>2. Перейдите в <strong>Настройки бота</strong></p>
          <p>3. Найдите поле <strong>"Google Sheet URL"</strong></p>
          <p>4. Вставьте скопированный URL таблицы</p>
          <p>5. Сохраните настройки</p>
          <p>6. Вернитесь сюда и нажмите <strong>"Тестировать подключение"</strong></p>
        </div>
      ),
    },
  ];

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  return (
    <div className="max-w-6xl mx-auto">
      <div className="mb-6">
        <h1 className="text-3xl font-bold text-gray-900">Интеграция с Google Sheets</h1>
        <p className="text-gray-600 mt-2">
          Настройка автоматической записи данных участников розыгрыша в Google Таблицу
        </p>
      </div>

      {/* Уведомления */}
      {error && (
        <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-start">
          <span className="text-2xl mr-3">❌</span>
          <div>
            <p className="font-semibold text-red-800">Ошибка</p>
            <p className="text-red-600">{error}</p>
          </div>
        </div>
      )}

      {success && (
        <div className="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-start">
          <span className="text-2xl mr-3">✅</span>
          <div>
            <p className="font-semibold text-green-800">Успешно</p>
            <p className="text-green-600">{success}</p>
          </div>
        </div>
      )}

      {/* Текущий статус */}
      <div className="bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 className="text-xl font-semibold mb-4 flex items-center">
          <span className="text-2xl mr-2">📊</span>
          Текущий статус
        </h2>

        <div className="space-y-4">
          <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
              <p className="font-medium text-gray-900">Интеграция</p>
              <p className="text-sm text-gray-600">
                {settings.enabled ? 'Активна' : 'Отключена'}
              </p>
            </div>
            <label className="relative inline-flex items-center cursor-pointer">
              <input
                type="checkbox"
                className="sr-only peer"
                checked={settings.enabled}
                onChange={handleToggleEnabled}
                disabled={!settings.hasCredentials}
              />
              <div className="w-14 h-7 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-600"></div>
            </label>
          </div>

          <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
            <div>
              <p className="font-medium text-gray-900">Ключ доступа</p>
              <p className="text-sm text-gray-600">
                {settings.hasCredentials ? (
                  <span className="text-green-600">✓ Загружен</span>
                ) : (
                  <span className="text-red-600">✗ Не загружен</span>
                )}
              </p>
            </div>
            {settings.hasCredentials && (
              <span className="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium">
                Активен
              </span>
            )}
          </div>
        </div>

        {!settings.hasCredentials && (
          <div className="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <p className="text-yellow-800">
              ⚠️ Для включения интеграции необходимо загрузить файл с ключом Service Account
            </p>
          </div>
        )}
      </div>

      {/* Загрузка ключа */}
      <div className="bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 className="text-xl font-semibold mb-4 flex items-center">
          <span className="text-2xl mr-2">🔑</span>
          Загрузка ключа Service Account
        </h2>

        <div className="space-y-4">
          <p className="text-gray-600">
            Загрузите JSON-файл с ключом Service Account, который вы создали в Google Cloud Console
          </p>

          <div className="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-blue-400 transition-colors">
            <input
              type="file"
              id="credentials-upload"
              accept=".json"
              onChange={handleFileUpload}
              className="hidden"
              disabled={uploading}
            />
            <label
              htmlFor="credentials-upload"
              className="cursor-pointer block"
            >
              <div className="text-6xl mb-2">📁</div>
              <p className="text-lg font-medium text-gray-700 mb-1">
                {uploading ? 'Загрузка...' : 'Нажмите для выбора файла'}
              </p>
              <p className="text-sm text-gray-500">
                JSON файл (service-account.json)
              </p>
            </label>
          </div>

          {uploadError && (
            <div className="p-3 bg-red-50 border border-red-200 rounded text-red-700">
              ❌ {uploadError}
            </div>
          )}

          {uploadSuccess && (
            <div className="p-3 bg-green-50 border border-green-200 rounded text-green-700">
              ✅ {uploadSuccess}
            </div>
          )}
        </div>
      </div>

      {/* Тестирование подключения */}
      <div className="bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 className="text-xl font-semibold mb-4 flex items-center">
          <span className="text-2xl mr-2">🧪</span>
          Тестирование подключения
        </h2>

        <p className="text-gray-600 mb-4">
          Проверьте, что Service Account имеет доступ к Google Таблице
        </p>

        <button
          onClick={handleTestConnection}
          disabled={!settings.hasCredentials || testing}
          className="px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors flex items-center"
        >
          {testing ? (
            <>
              <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-white mr-2"></div>
              Тестирование...
            </>
          ) : (
            <>
              <span className="mr-2">🔍</span>
              Тестировать подключение
            </>
          )}
        </button>

        {testResult && (
          <div className={`mt-4 p-4 rounded-lg border ${
            testResult.success
              ? 'bg-green-50 border-green-200'
              : 'bg-red-50 border-red-200'
          }`}>
            <p className={`font-semibold ${
              testResult.success ? 'text-green-800' : 'text-red-800'
            }`}>
              {testResult.success ? '✅ Подключение успешно' : '❌ Ошибка подключения'}
            </p>
            <p className={testResult.success ? 'text-green-600' : 'text-red-600'}>
              {testResult.message}
            </p>
            {testResult.details && (
              <div className="mt-2 text-sm">
                {testResult.details.map((detail, idx) => (
                  <p key={idx} className="text-gray-700">• {detail}</p>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Инструкции */}
      <div className="bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 className="text-xl font-semibold mb-4 flex items-center">
          <span className="text-2xl mr-2">📚</span>
          Пошаговая инструкция
        </h2>

        <p className="text-gray-600 mb-4">
          Следуйте этим шагам для настройки интеграции с Google Sheets
        </p>

        <div className="space-y-3">
          {instructionSteps.map((step) => (
            <div key={step.id} className="border border-gray-200 rounded-lg overflow-hidden">
              <button
                onClick={() => toggleStep(step.id)}
                className="w-full px-4 py-3 bg-gray-50 hover:bg-gray-100 flex items-center justify-between transition-colors"
              >
                <span className="font-medium text-left">{step.title}</span>
                <span className="text-2xl transform transition-transform">
                  {expandedStep === step.id ? '−' : '+'}
                </span>
              </button>
              {expandedStep === step.id && (
                <div className="px-4 py-4 bg-white text-gray-700">
                  {step.content}
                </div>
              )}
            </div>
          ))}
        </div>
      </div>

      {/* Полная документация */}
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-6">
        <h3 className="text-lg font-semibold text-blue-900 mb-2 flex items-center">
          <span className="text-2xl mr-2">📖</span>
          Полная документация
        </h3>
        <p className="text-blue-800 mb-3">
          Подробное руководство с примерами кода и troubleshooting
        </p>
        <a
          href="https://github.com/letoceiling-coder/check-parser/blob/main/docs/GOOGLE_SHEETS_SETUP.md"
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors"
        >
          <span className="mr-2">🔗</span>
          Открыть полную документацию
        </a>
      </div>
    </div>
  );
}

export default GoogleSheetsSettings;
