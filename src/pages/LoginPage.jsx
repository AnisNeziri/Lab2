import React, { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import api from "../services/api";
import "./LoginPage.css";

export default function LoginPage() {
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [banner, setBanner] = useState(null);
  const [loading, setLoading] = useState(false);

  const onSubmit = async (e) => {
    e.preventDefault();
    setBanner(null);

    if (!email || !password) {
      return setBanner({ type: "error", text: "Email and password are required." });
    }

    setLoading(true);
    try {
      const res = await api.post("/login", { email, password });
      const token = res?.data?.token;

      if (token) {
        localStorage.setItem("token", token);
      }

      setBanner({ type: "success", text: "Welcome back!" });
      navigate("/dashboard");
    } catch (err) {
      setBanner({
        type: "error",
        text: err?.response?.data?.message || "Invalid credentials.",
      });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <form className="lp-card" onSubmit={onSubmit} noValidate>
        <h2>Log in to AIMS</h2>

        {banner?.type === "success" && (
          <div className="lp-banner success">{banner.text}</div>
        )}
        {banner?.type === "error" && (
          <div className="lp-banner error">{banner.text}</div>
        )}

        <input
          type="email"
          placeholder="Email address"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoComplete="username"
          required
        />
        <input
          type="password"
          placeholder="Password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          autoComplete="current-password"
          required
        />

        <button type="submit" disabled={loading}>
          {loading ? "Logging in..." : "Login"}
        </button>

        <div className="lp-footer">
          <span>Don’t have an account?</span>
          <Link to="/register">Register</Link>
        </div>

        {/* 🏠 Back to Home Button */}
        <button
          type="button"
          className="back-home-btn"
          onClick={() => navigate("/")}
        >
          ← Back to Home
        </button>
      </form>
    </div>
  );
}
