import React, { useState } from 'react';
import Sidebar from './Sidebar';

function Layout({ children, user, onLogout }) {
  const [sidebarOpen, setSidebarOpen] = useState(true);

  return (
    <div className="min-h-screen bg-gray-100 flex">
      <Sidebar 
        isOpen={sidebarOpen} 
        onToggle={() => setSidebarOpen(!sidebarOpen)}
        user={user}
        onLogout={onLogout}
      />
      <main className={`flex-1 transition-all duration-300 ${sidebarOpen ? 'ml-64' : 'ml-16'}`}>
        <div className="p-6">
          {children}
        </div>
      </main>
    </div>
  );
}

export default Layout;
