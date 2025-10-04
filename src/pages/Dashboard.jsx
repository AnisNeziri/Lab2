import React from 'react';
import { Typography, Container, Box } from '@mui/material';

const Dashboard = () => {
  return (
    <Container maxWidth="md">
      <Box sx={{ mt: 4 }}>
        <Typography variant="h3" gutterBottom>
          Dashboard
        </Typography>
        <Typography>
          Welcome to your inventory system dashboard.
        </Typography>
      </Box>
    </Container>
  );
};

export default Dashboard;
