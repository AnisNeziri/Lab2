// src/pages/LandingPage.jsx
import React, { useEffect, useContext } from 'react';
import { useNavigate } from 'react-router-dom';
import { AppBar, Toolbar, Typography, Button, Box, Container, Grid, Paper } from '@mui/material';
import { AuthContext } from '../context/AuthContext';

const companies = [
  { name: 'Acme Corp', logo: 'https://via.placeholder.com/100?text=Acme' },
  { name: 'Beta Ltd', logo: 'https://via.placeholder.com/100?text=Beta' },
  { name: 'Gamma Inc', logo: 'https://via.placeholder.com/100?text=Gamma' },
  // Add more placeholder companies here
];

export default function LandingPage() {
  const { user } = useContext(AuthContext);
  const navigate = useNavigate();

  useEffect(() => {
    // If a user is already logged in, redirect to dashboard
    if (user) {
      navigate('/dashboard', { replace: true });
    }
  }, [user, navigate]);

  return (
    <>
      {/* Header */}
      <AppBar position="static" color="transparent" elevation={0} sx={{ padding: 1 }}>
        <Toolbar sx={{ display: 'flex', justifyContent: 'space-between' }}>
          <Typography variant="h6" sx={{ fontWeight: 'bold', color: '#1976d2' }}>
            AIMS
          </Typography>
          <Box>
            <Button href="/register" sx={{ marginRight: 1 }}>Register</Button>
            <Button href="/login" variant="outlined">Log In</Button>
          </Box>
        </Toolbar>
      </AppBar>

      {/* Main Content */}
      <Container sx={{ mt: 6 }}>
        <Grid container spacing={4} alignItems="center">
          <Grid item xs={12} md={6}>
            <Typography variant="h3" gutterBottom>
              Welcome to AIMS
            </Typography>
            <Typography variant="body1" paragraph>
              AIMS is your all-in-one inventory management system designed for companies of all sizes. 
              Track products, manage transactions, and optimize your stock effortlessly.
            </Typography>
            <Typography variant="body1" paragraph>
              Join many companies using AIMS to streamline their operations.
            </Typography>

            {/* Contact Info */}
            <Box mt={4} sx={{ borderTop: '1px solid #ddd', pt: 2 }}>
              <Typography variant="subtitle1">Contact Us</Typography>
              <Typography variant="body2">Email: support@aims.com</Typography>
              <Typography variant="body2">Phone: +1 (555) 123-4567</Typography>
              <Typography variant="body2">Address: 123 Inventory St, Business City</Typography>
            </Box>
          </Grid>

          <Grid item xs={12} md={6}>
            {/* Inventory Image */}
            <Box
              component="img"
              src="https://images.unsplash.com/photo-1581091012184-45bf760f0b0c?auto=format&fit=crop&w=600&q=80"
              alt="Inventory"
              sx={{ width: '100%', borderRadius: 2, boxShadow: 3 }}
            />
          </Grid>
        </Grid>

        {/* Companies slider */}
        <Box mt={8}>
          <Typography variant="h5" gutterBottom>Companies Using AIMS</Typography>
          <Grid container spacing={2}>
            {companies.map((company) => (
              <Grid item key={company.name}>
                <Paper elevation={3} sx={{ p: 2, textAlign: 'center' }}>
                  <Box
                    component="img"
                    src={company.logo}
                    alt={company.name}
                    sx={{ width: 100, height: 100, objectFit: 'contain', mb: 1 }}
                  />
                  <Typography>{company.name}</Typography>
                </Paper>
              </Grid>
            ))}
          </Grid>
        </Box>
      </Container>

      {/* Footer */}
      <Box mt={10} mb={4} textAlign="center" color="text.secondary">
        © 2025 AIMS. All rights reserved.
      </Box>
    </>
  );
}
