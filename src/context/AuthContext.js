import React, { createContext, useState, useEffect } from "react";
import api from "../services/api";
import { useNavigate } from "react-router-dom";

export const AuthContext = createContext();

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const navigate = useNavigate();

  useEffect(() => {
    const fetchUser = async () => {
      const token = localStorage.getItem("auth_token");
      if (!token) {
        setLoading(false);
        return;
      }

      try {
        api.defaults.headers.common.Authorization = `Bearer ${token}`;
        const res = await api.get("/me");
        setUser(res.data);
      } catch {
        setUser(null);
        localStorage.removeItem("auth_token");
        delete api.defaults.headers.common.Authorization;
      } finally {
        setLoading(false);
      }
    };
    fetchUser();
  }, []);

  const login = async (email, password) => {
    const res = await api.post("/login", { email, password });
    const token = res.data?.token;
    if (!token) {
      throw new Error("Missing auth token.");
    }

    localStorage.setItem("auth_token", token);
    api.defaults.headers.common.Authorization = `Bearer ${token}`;

    const userRes = await api.get("/me");
    setUser(userRes.data);

    navigate(userRes.data?.role === "ceo" ? "/dashboard" : "/products");
    return true;
  };

  const logout = async () => {
    try {
      await api.post("/logout");
    } catch {}

    setUser(null);
    localStorage.removeItem("auth_token");
    delete api.defaults.headers.common.Authorization;
    navigate("/login");
  };

  return (
    <AuthContext.Provider value={{ user, setUser, login, logout, loading }}>
      {children}
    </AuthContext.Provider>
  );
};
