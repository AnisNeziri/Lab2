import React from "react";
import { Container, Typography, Button, Box } from "@mui/material";

const ReportsPage = () => {
  return (
    <Container>
      <Typography variant="h4" sx={{ mb: 2 }}>Reports</Typography>
      <Box sx={{ display: "flex", flexDirection: "column", gap: 2 }}>
        <Button variant="outlined">Inventory Value</Button>
        <Button variant="outlined">Sales Report</Button>
        <Button variant="outlined">Inventory Value Over Time</Button>
        <Button variant="outlined">Sales Trends</Button>
        <Button variant="outlined">Top Selling Products</Button>
      </Box>
    </Container>
  );
};

export default ReportsPage;
