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

  const onCompanyChange = (e) => {
    const { name, value } = e.target;
    setCompany((prev) => ({ ...prev, [name]: value }));
  };

  const onSubmitCompany = async (e) => {
    e.preventDefault();
    setBanner(null);

    if (!company.name.trim())
      return setBanner({ type: "error", text: "Company name is required." });
    if (!company.manager_name.trim() || !company.manager_surname.trim())
      return setBanner({
        type: "error",
        text: "Manager name and surname are required.",
      });
    if (!company.email.trim())
      return setBanner({ type: "error", text: "Email is required." });
    if (!company.phone.trim())
      return setBanner({ type: "error", text: "Phone number is required." });
    if (company.password.length < 8)
      return setBanner({
        type: "error",
        text: "Password must be at least 8 characters.",
      });

    setSubmitting(true);
    try {
      const res = await api.post("/register", company);
      setBanner({
        type: "success",
        text: res?.data?.message || "Company registered successfully.",
      });

      setCompany({
        name: "",
        manager_name: "",
        manager_surname: "",
        email: "",
        phone: "",
        password: "",
        address: "",
      });
    } catch (err) {
      const firstValidation =
        err?.response?.data?.errors &&
        Object.values(err.response.data.errors)[0]?.[0];

      setBanner({
        type: "error",
        text:
          firstValidation ||
          err?.response?.data?.message ||
          "Registration failed.",
      });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="register-page">
      <h2 className="rp-title">Create your AIMS account</h2>

      <div className={containerClass}>
        {/* Company Form */}
        <div className="form-container sign-up-container">
          <form className="rp-form" onSubmit={onSubmitCompany} noValidate>
            <h1>Register / Company</h1>

            {banner?.type === "success" && (
              <div className="rp-banner success">{banner.text}</div>
            )}
            {banner?.type === "error" && (
              <div className="rp-banner error">{banner.text}</div>
            )}

            <input
              type="text"
              name="name"
              placeholder="Company Name"
              value={company.name}
              onChange={onCompanyChange}
              required
            />
            <div className="rp-row">
              <input
                type="text"
                name="manager_name"
                placeholder="Manager Name"
                value={company.manager_name}
                onChange={onCompanyChange}
                required
              />
              <input
                type="text"
                name="manager_surname"
                placeholder="Manager Surname"
                value={company.manager_surname}
                onChange={onCompanyChange}
                required
              />
            </div>
            <input
              type="email"
              name="email"
              placeholder="Email"
              value={company.email}
              onChange={onCompanyChange}
              required
            />
            <input
              type="text"
              name="phone"
              placeholder="Phone"
              value={company.phone}
              onChange={onCompanyChange}
              required
            />
            <input
              type="password"
              name="password"
              placeholder="Password (min 8 chars)"
              value={company.password}
              onChange={onCompanyChange}
              required
            />
            <input
              type="text"
              name="address"
              placeholder="Company Address (Optional)"
              value={company.address}
              onChange={onCompanyChange}
            />

            <button type="submit" disabled={submitting}>
              {submitting ? "Registering..." : "Create Company"}
            </button>

            <div className="rp-alt">
              Already have an account? <Link to="/login">Log In</Link>
            </div>

            {/* 🏠 Back to Home Button */}
            <button
              type="button"
              className="rp-back-btn"
              onClick={() => navigate("/")}
            >
              ⬅ Back to Home
            </button>
          </form>
        </div>

        {/* User Form (Preview) */}
        <div className="form-container sign-in-container">
          <form className="rp-form" onSubmit={(e) => e.preventDefault()}>
            <h1>Register / User</h1>
            <div className="rp-banner info">
              Coming soon — user registration enabled after company onboarding.
            </div>
            <div className="rp-row">
              <input type="text" placeholder="First Name" disabled />
              <input type="text" placeholder="Last Name" disabled />
            </div>
            <input type="email" placeholder="Email" disabled />
            <input type="password" placeholder="Password" disabled />
            <input type="text" placeholder="Department (Optional)" disabled />
            <button type="button" className="disabled" disabled>
              Create User
            </button>
            <div className="rp-alt">
              Already have an account? <Link to="/login">Log In</Link>
            </div>

            {/* 🏠 Back to Home Button */}
            <button
              type="button"
              className="rp-back-btn"
              onClick={() => navigate("/")}
            >
              ⬅ Back to Home
            </button>
          </form>
        </div>

        {/* Overlay */}
        <div className="overlay-container">
          <div className="overlay">
            <div className="overlay-panel overlay-left">
              <h1>Register / User</h1>
              <p>Invite staff and assign roles—available after company setup.</p>
              <button className="ghost" onClick={() => setShowUserForm(false)}>
                User Form (Preview)
              </button>
            </div>
            <div className="overlay-panel overlay-right">
              <h1>Register / Company</h1>
              <p>Register as Company Owner with full permissions.</p>
              <button className="ghost" onClick={() => setShowUserForm(true)}>
                Company Form
              </button>
            </div>
          </div>
        </div>
      </div>

      <div className="rp-mini-toggle">
        <button
          className={!showUserForm ? "active" : ""}
          onClick={() => setShowUserForm(false)}
        >
          User (Preview)
        </button>
        <button
          className={showUserForm ? "active" : ""}
          onClick={() => setShowUserForm(true)}
        >
          Company
        </button>
      </div>
    </div>
  );
}
