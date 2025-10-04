import React, { useState } from "react";
import api from "../services/api";
import "./RegisterPage.css";

const RegisterPage = () => {
  const [formData, setFormData] = useState({
    name: "",
    surname: "",
    company_name: "",
    email: "",
    password: "",
    address: "",
    phone: ""
  });

  const [message, setMessage] = useState("");
  const [errors, setErrors] = useState({});
  const [showUserForm, setShowUserForm] = useState(false);

  const handleChange = (e) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setMessage("");
    setErrors({});
    try {
      const res = await api.post("/register", formData);
      if (res.data.success) {
        setMessage("Company registered successfully!");
        setFormData({
          name: "",
          surname: "",
          company_name: "",
          email: "",
          password: "",
          address: "",
          phone: ""
        });
      }
    } catch (err) {
      if (err.response && err.response.data.errors) {
        setErrors(err.response.data.errors);
      } else if (err.response && err.response.data.message) {
        setMessage(err.response.data.message);
      }
    }
  };

  return (
    <div className="container">
      <div className={`form-container ${showUserForm ? "sign-up-container" : "sign-in-container"}`}>
        {/* Company Registration Form */}
        {!showUserForm && (
          <form onSubmit={handleSubmit}>
            <h1>Register Company</h1>
            {message && <p className="success-message">{message}</p>}
            <input
              type="text"
              name="name"
              placeholder="Name"
              value={formData.name}
              onChange={handleChange}
            />
            {errors.name && <p className="error-message">{errors.name[0]}</p>}
            <input
              type="text"
              name="surname"
              placeholder="Surname"
              value={formData.surname}
              onChange={handleChange}
            />
            {errors.surname && <p className="error-message">{errors.surname[0]}</p>}
            <input
              type="text"
              name="company_name"
              placeholder="Company Name"
              value={formData.company_name}
              onChange={handleChange}
            />
            {errors.company_name && <p className="error-message">{errors.company_name[0]}</p>}
            <input
              type="email"
              name="email"
              placeholder="Email"
              value={formData.email}
              onChange={handleChange}
            />
            {errors.email && <p className="error-message">{errors.email[0]}</p>}
            <input
              type="password"
              name="password"
              placeholder="Password"
              value={formData.password}
              onChange={handleChange}
            />
            {errors.password && <p className="error-message">{errors.password[0]}</p>}
            <input
              type="text"
              name="address"
              placeholder="Address"
              value={formData.address}
              onChange={handleChange}
            />
            <input
              type="text"
              name="phone"
              placeholder="Phone"
              value={formData.phone}
              onChange={handleChange}
            />
            <button type="submit">Register Company</button>
            <p className="toggle-form" onClick={() => setShowUserForm(true)}>
              Register as User
            </p>
          </form>
        )}

        {/* User Registration Form (disabled) */}
        {showUserForm && (
          <form>
            <h1>Register User</h1>
            <p>This form is currently disabled.</p>
            <button type="button" onClick={() => setShowUserForm(false)}>
              Back to Company
            </button>
          </form>
        )}
      </div>

      {/* Link to login page */}
      <div className="login-link">
        <p>
          Already have an account? <a href="/login">Log In</a>
        </p>
      </div>
    </div>
  );
};

export default RegisterPage;
