import React, { createContext, useState, useEffect } from "react";
import api from "../services/api";

export const AuthContext = createContext();

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);

  // Fetch user info on mount if token exists
  useEffect(() => {
    const fetchUser = async () => {
      const token = localStorage.getItem("token");
      if (!token) return;
      try {
        const res = await api.get("/user");
        setUser(res.data);
      } catch {
        localStorage.removeItem("token");
      }
    };
    fetchUser();
  }, []);

  const login = async (email, password) => {
    const res = await api.post("/login", { email, password });
    const token = res?.data?.token;
    if (token) localStorage.setItem("token", token);
    const userRes = await api.get("/user");
    setUser(userRes.data);
  };

  const logout = () => {
    localStorage.removeItem("token");
    setUser(null);
  };

  return (
    <AuthContext.Provider value={{ user, setUser, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
};
