import React, { useState, useEffect } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import axios from 'axios';
import LandingPage from './pages/LandingPage';
import LoginPage from './pages/LoginPage';
import UsersPage from './pages/UsersPage';

// Configure axios defaults
axios.defaults.baseURL = 'http://127.0.0.1:8000/api';
axios.interceptors.request.use(config => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

const App = () => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  // Validate token with backend on initial load
  useEffect(() => {
    const validateToken = async () => {
      const token = localStorage.getItem('token');
      if (!token) {
        setLoading(false);
        return;
      }

      try {
        const response = await axios.get('/validate-token');
        setUser(response.data.user);
      } catch (error) {
        localStorage.removeItem('token');
      } finally {
        setLoading(false);
      }
    };

    validateToken();
  }, []);

  const handleLogin = async (loginData) => {
    try {
      const response = await axios.post('/login', loginData);
      const { token, user } = response.data;
      
      localStorage.setItem('token', token);
      setUser(user);
      return { success: true };
    } catch (error) {
      return { success: false, error: error.response?.data?.message || 'Login failed' };
    }
  };

  const handleLogout = async () => {
    try {
      await axios.post('/logout');
    } finally {
      localStorage.removeItem('token');
      setUser(null);
    }
  };

  // Show loading screen while checking token
  if (loading) {
    return <div className="flex justify-center items-center h-screen">Loading...</div>;
  }

  return (
    <Router>
      <Routes>
        {/* Public landing page */}
        <Route path="/" element={<LandingPage />} />

        {/* Login page only if user is not logged in */}
        <Route
          path="/login"
          element={
            !user
              ? <LoginPage onLogin={handleLogin} />
              : <Navigate to="/users" replace />
          }
        />

        {/* Protected route */}
        <Route
          path="/users"
          element={
            user
              ? <UsersPage onLogout={handleLogout} user={user} />
              : <Navigate to="/" replace />
          }
        />

        {/* Any unknown route → send to landing page */}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Router>
  );
};

export default App;
