import React, { useMemo, useState } from "react";
import api from "../services/api";
import { Link, useNavigate } from "react-router-dom";
import "./RegisterPage.css";

export default function RegisterPage() {
  const [showUserForm, setShowUserForm] = useState(false);
  const navigate = useNavigate();

  const [company, setCompany] = useState({
    name: "",
    manager_name: "",
    manager_surname: "",
    email: "",
    phone: "",
    password: "",
    address: "",
  });

  const [banner, setBanner] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const containerClass = useMemo(
    () => `rp-container ${showUserForm ? "right-panel-active" : ""}`,
    [showUserForm]
  );

  const handleChange = (e) => {
    const { name, value } = e.target;
    setCompany((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setBanner(null);
    setSubmitting(true);

    try {
      const res = await api.post("/register", company);
      setBanner({
        type: "success",
        text: res?.data?.message || "Company registered successfully!",
      });

      // Reset fields
      setCompany({
        name: "",
        manager_name: "",
        manager_surname: "",
        email: "",
        phone: "",
        password: "",
        address: "",
      });

      // Redirect after short delay
      setTimeout(() => navigate("/login"), 2000);
    } catch (err) {
      const validationError =
        err?.response?.data?.errors &&
        Object.values(err.response.data.errors)[0]?.[0];

      setBanner({
        type: "error",
        text:
          validationError ||
          err?.response?.data?.message ||
          "Registration failed. Please try again.",
      });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="register-page">
      <h2 className="rp-title">Create Your AIMS Company Account</h2>

      <div className={containerClass}>
        {/* Company Registration */}
        <div className="form-container sign-up-container">
          <form className="rp-form" onSubmit={handleSubmit} noValidate>
            <h1>Company Registration</h1>

            {banner && (
              <div className={`rp-banner ${banner.type}`}>{banner.text}</div>
            )}

            <input
              type="text"
              name="name"
              placeholder="Company Name"
              value={company.name}
              onChange={handleChange}
              required
            />

            <div className="rp-row">
              <input
                type="text"
                name="manager_name"
                placeholder="Manager Name"
                value={company.manager_name}
                onChange={handleChange}
                required
              />
              <input
                type="text"
                name="manager_surname"
                placeholder="Manager Surname"
                value={company.manager_surname}
                onChange={handleChange}
                required
              />
            </div>

            <input
              type="email"
              name="email"
              placeholder="Email"
              value={company.email}
              onChange={handleChange}
              required
            />
            <input
              type="text"
              name="phone"
              placeholder="Phone"
              value={company.phone}
              onChange={handleChange}
              required
            />
            <input
              type="password"
              name="password"
              placeholder="Password (min 8 chars)"
              value={company.password}
              onChange={handleChange}
              required
            />
            <input
              type="text"
              name="address"
              placeholder="Company Address (Optional)"
              value={company.address}
              onChange={handleChange}
            />

            <button type="submit" disabled={submitting}>
              {submitting ? "Registering..." : "Create Company"}
            </button>

            <div className="rp-alt">
              Already have an account? <Link to="/login">Log In</Link>
            </div>

            <button
              type="button"
              className="rp-back-btn"
              onClick={() => navigate("/")}
            >
              ⬅ Back to Home
            </button>
          </form>
        </div>

        {/* Placeholder for User registration (future feature) */}
        <div className="form-container sign-in-container">
          <form className="rp-form">
            <h1>Register User</h1>
            <div className="rp-banner info">
              Coming soon — user registration will be available after company onboarding.
            </div>
            <button type="button" className="rp-back-btn" onClick={() => setShowUserForm(false)}>
              Back to Company Registration
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
