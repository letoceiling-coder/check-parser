import React from 'react';
import './Dashboard.css';

function Dashboard({ user, onLogout }) {
  return (
    <div className="dashboard-container">
      <header className="dashboard-header">
        <h1>Welcome, {user.name}!</h1>
        <button onClick={onLogout} className="logout-btn">Logout</button>
      </header>
      <div className="dashboard-content">
        <div className="user-info">
          <h2>User Information</h2>
          <p><strong>Username:</strong> {user.username}</p>
          <p><strong>Name:</strong> {user.name}</p>
          <p><strong>Email:</strong> {user.email}</p>
        </div>
      </div>
    </div>
  );
}

export default Dashboard;
