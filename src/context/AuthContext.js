import React, { createContext, useState, useEffect } from "react";
import api from "../services/api";
import { useNavigate } from "react-router-dom";

export const AuthContext = createContext();

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const navigate = useNavigate();

  // Fetch logged-in user info
  useEffect(() => {
    const fetchUser = async () => {
      try {
        const res = await api.get("/me");
        setUser(res.data);
      } catch (err) {
        setUser(null);
      } finally {
        setLoading(false);
      }
    };
    fetchUser();
  }, []);

  // Login function
  const login = async (email, password) => {
    try {
      const res = await api.post("/login", { email, password });
      api.defaults.headers.common["Authorization"] = `Bearer ${res.data.token}`;
      const userRes = await api.get("/me");
      setUser(userRes.data);

      // Redirect based on role
      if (userRes.data.role === "ceo") navigate("/dashboard");
      else navigate("/dashboard"); // you can customize for staff pages
    } catch (err) {
      throw err;
    }
  };

  // Logout
  const logout = async () => {
    try {
      await api.post("/logout");
    } catch (err) {
      console.error(err);
    } finally {
      setUser(null);
      localStorage.removeItem("auth_token");
      navigate("/login");
    }
  };

  return (
    <AuthContext.Provider value={{ user, setUser, login, logout, loading }}>
      {children}
    </AuthContext.Provider>
  );
};
