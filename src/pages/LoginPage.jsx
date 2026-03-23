import React, { useState, useContext } from "react";
import { AuthContext } from "../context/AuthContext";
import { Link } from "react-router-dom";
import "./LoginPage.css";

export default function LoginPage() {
  const { login } = useContext(AuthContext);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError("");
    try {
      await login(email, password);
    } catch {
      setError("Invalid email or password.");
    }
    setLoading(false);
  };

  return (
    <div className="login-page">
      <div className="lp-card">
        <h2>Login to AIMS</h2>
        {error && <div className="lp-banner error">{error}</div>}
        <form onSubmit={handleSubmit} className="lp-form">
          <input
            type="email"
            placeholder="Email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
          <input
            type="password"
            placeholder="Password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
          <button type="submit" disabled={loading}>
            {loading ? "Logging in..." : "Login"}
          </button>
        </form>

        {/* Extra links */}
        <div className="lp-footer" style={{ marginTop: "12px" }}>
          <Link to="/register">Register</Link>
        </div>
      </div>
    </div>
  );
}
