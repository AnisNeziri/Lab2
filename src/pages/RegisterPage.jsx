import React, { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import api from "../services/api";
import "./RegisterPage.css";

export default function RegisterPage() {
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
  const [loading, setLoading] = useState(false);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setCompany((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setBanner(null);

    if (!company.name || !company.manager_name || !company.manager_surname || !company.email || !company.phone || company.password.length < 8) {
      setBanner({ type: "error", text: "Please fill all required fields and ensure password is at least 8 chars." });
      return;
    }

    setLoading(true);
    try {
      const res = await api.post("/register", company);
      setBanner({ type: "success", text: res?.data?.message || "Company registered successfully." });
      setCompany({ name: "", manager_name: "", manager_surname: "", email: "", phone: "", password: "", address: "" });
    } catch (err) {
      const message = err?.response?.data?.message || "Registration failed.";
      setBanner({ type: "error", text: message });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="register-page">
      <h2>Create your AIMS Company Account</h2>
      <form className="rp-form" onSubmit={handleSubmit}>
        {banner && <div className={`rp-banner ${banner.type}`}>{banner.text}</div>}
        <input type="text" name="name" placeholder="Company Name" value={company.name} onChange={handleChange} required />
        <div className="rp-row">
          <input type="text" name="manager_name" placeholder="Manager Name" value={company.manager_name} onChange={handleChange} required />
          <input type="text" name="manager_surname" placeholder="Manager Surname" value={company.manager_surname} onChange={handleChange} required />
        </div>
        <input type="email" name="email" placeholder="Email" value={company.email} onChange={handleChange} required />
        <input type="text" name="phone" placeholder="Phone" value={company.phone} onChange={handleChange} required />
        <input type="password" name="password" placeholder="Password" value={company.password} onChange={handleChange} required />
        <input type="text" name="address" placeholder="Company Address (Optional)" value={company.address} onChange={handleChange} />
        <button type="submit" disabled={loading}>{loading ? "Registering..." : "Register"}</button>
        <div className="rp-alt">Already have an account? <Link to="/login">Log In</Link></div>
        <button type="button" className="rp-back-btn" onClick={() => navigate("/")}>⬅ Back to Home</button>
      </form>
    </div>
  );
}
