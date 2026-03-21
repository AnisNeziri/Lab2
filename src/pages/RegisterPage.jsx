import React, { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import api from "../services/api";
import "./RegisterPage.css";

export default function RegisterPage() {
  const navigate = useNavigate();
  const [form, setForm] = useState({
    name: "", // ✅ company name
    manager_name: "",
    manager_surname: "",
    email: "",
    phone: "",
    password: "",
    address: "",
  });
  const [banner, setBanner] = useState(null);
  const [loading, setLoading] = useState(false);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setBanner(null);

    // Basic validation before sending
    if (
      !form.name ||
      !form.manager_name ||
      !form.manager_surname ||
      !form.email ||
      !form.phone ||
      form.password.length < 8
    ) {
      setBanner({
        type: "error",
        text: "Please fill in all required fields. Password must be at least 8 characters.",
      });
      return;
    }

    setLoading(true);
    try {
      const res = await api.post("/register", form);
      setBanner({
        type: "success",
        text: res?.data?.message || "Company registered successfully!",
      });
      setTimeout(() => navigate("/login"), 2000);
    } catch (err) {
      const message = err?.response?.data?.message || "Registration failed.";
      setBanner({ type: "error", text: message });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="register-page">
      <h2>Create Your Company Account</h2>
      <form className="rp-form" onSubmit={handleSubmit}>
        {banner && <div className={`rp-banner ${banner.type}`}>{banner.text}</div>}

        <input
          type="text"
          name="name"
          placeholder="Company Name"
          value={form.name}
          onChange={handleChange}
          required
        />

        <div className="rp-row">
          <input
            type="text"
            name="manager_name"
            placeholder="Manager First Name"
            value={form.manager_name}
            onChange={handleChange}
            required
          />
          <input
            type="text"
            name="manager_surname"
            placeholder="Manager Surname"
            value={form.manager_surname}
            onChange={handleChange}
            required
          />
        </div>

        <input
          type="email"
          name="email"
          placeholder="Email"
          value={form.email}
          onChange={handleChange}
          required
        />
        <input
          type="text"
          name="phone"
          placeholder="Phone"
          value={form.phone}
          onChange={handleChange}
          required
        />
        <input
          type="password"
          name="password"
          placeholder="Password"
          value={form.password}
          onChange={handleChange}
          required
        />
        <input
          type="text"
          name="address"
          placeholder="Company Address (optional)"
          value={form.address}
          onChange={handleChange}
        />

        <button type="submit" disabled={loading}>
          {loading ? "Registering..." : "Register"}
        </button>

        <div className="rp-alt">
          Already have an account? <Link to="/login">Log In</Link>
        </div>

        <button type="button" className="rp-back-btn" onClick={() => navigate("/")}>
          ⬅ Back to Home
        </button>
      </form>
    </div>
  );
}
