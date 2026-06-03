import axios from "axios";

const api = axios.create({
  baseURL: "http://localhost:8000/api", // Adjust to your backend URL
  headers: {
    "Content-Type": "application/json",
  },
});

// Automatically attach token if exists
api.interceptors.request.use((config) => {
  const token = localStorage.getItem("auth_token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem("auth_token");
      delete api.defaults.headers.common.Authorization;
      const path = window.location.pathname || "";
      if (!path.startsWith("/login") && !path.startsWith("/register")) {
        window.location.assign("/login");
      }
    }
    return Promise.reject(err);
  }
);

export default api;
