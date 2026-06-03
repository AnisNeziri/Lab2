import React, { useContext } from "react";
import { AppBar, Toolbar, Typography, Button, Box } from "@mui/material";
import { AuthContext } from "../context/AuthContext";
import { useNavigate, useLocation } from "react-router-dom";

const Layout = ({ children }) => {
  const { user, logout } = useContext(AuthContext);
  const navigate = useNavigate();
  const location = useLocation();

  // Hide navbar on landing page
  const isLandingPage = location.pathname === "/";
  const homePath = user?.role === "ceo" ? "/dashboard" : "/products";

  return (
    <>
      {!isLandingPage && (
        <AppBar position="static" color="primary">
          <Toolbar sx={{ display: "flex", justifyContent: "space-between" }}>
            <Typography
              variant="h6"
              sx={{ cursor: "pointer" }}
              onClick={() => navigate(homePath)}
            >
              AIMS
            </Typography>
            {user ? (
              <Box sx={{ display: "flex", alignItems: "center", gap: 2, flexWrap: "wrap" }}>
                {user.role === "ceo" && (
                  <Button color="inherit" onClick={() => navigate("/dashboard")}>Dashboard</Button>
                )}
                <Button color="inherit" onClick={() => navigate("/products")}>Products</Button>
                <Button color="inherit" onClick={() => navigate("/suppliers")}>Suppliers</Button>
                <Button color="inherit" onClick={() => navigate("/transactions")}>Transactions</Button>
                <Button color="inherit" onClick={() => navigate("/inventory")}>Inventory</Button>
                <Button color="inherit" onClick={() => navigate("/reports")}>Reports</Button>
                <Button color="inherit" onClick={() => navigate("/invoices")}>Invoices</Button>
                {(user.role === "ceo" || user.role === "admin") && (
                  <Button color="inherit" onClick={() => navigate("/users")}>Staff</Button>
                )}
                <Typography>{user.name}</Typography>
                <Button variant="outlined" onClick={logout}>
                  Logout
                </Button>
              </Box>
            ) : (
              <Box>
                <Button color="inherit" onClick={() => navigate("/login")}>
                  Login
                </Button>
              </Box>
            )}
          </Toolbar>
        </AppBar>
      )}
      <Box sx={{ padding: 2 }}>{children}</Box>
    </>
  );
};

export default Layout;
