import React, { useEffect, useMemo, useState } from "react";
import { Card, CardContent, Container, Grid, LinearProgress, Table, TableBody, TableCell, TableHead, TableRow, Typography } from "@mui/material";
import api from "../services/api";
import LoadingState from "../components/LoadingState";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const Dashboard = () => {
  const [overview, setOverview] = useState(null);
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });

  useEffect(() => {
    const loadDashboard = async () => {
      setLoading(true);
      try {
        const [overviewRes, productsRes] = await Promise.all([
          api.get("/dashboard/overview"),
          api.get("/dashboard/products"),
        ]);
        setOverview(overviewRes.data);
        setProducts(productsRes.data || []);
      } catch {
        setToast({ open: true, severity: "error", message: "Failed to load dashboard data." });
      } finally {
        setLoading(false);
      }
    };

    loadDashboard();
  }, []);

  const lowStockProducts = useMemo(
    () => products.filter((product) => product.stock_status === "low"),
    [products]
  );

  const chartData = overview
    ? [
        { name: "Low", value: overview.lowStock, color: "error" },
        { name: "Normal", value: overview.normalStock, color: "primary" },
        { name: "High", value: overview.highStock, color: "success" },
      ]
    : [];

  if (loading) {
    return (
      <Container sx={{ mt: 4 }}>
        <LoadingState label="Loading dashboard..." />
      </Container>
    );
  }

  return (
    <Container sx={{ mt: 3 }}>
      <Typography variant="h4" sx={{ mb: 3 }}>
        Dashboard
      </Typography>
      {overview && (
        <Grid container spacing={2} sx={{ mb: 3 }}>
          {[
            ["Total Products", overview.totalProducts],
            ["Low Stock", overview.lowStock],
            ["Normal Stock", overview.normalStock],
            ["High Stock", overview.highStock],
          ].map(([label, value]) => (
            <Grid item xs={12} sm={6} md={3} key={label}>
              <Card>
                <CardContent>
                  <Typography color="text.secondary">{label}</Typography>
                  <Typography variant="h5">{value}</Typography>
                </CardContent>
              </Card>
            </Grid>
          ))}
        </Grid>
      )}

      <Grid container spacing={2} sx={{ mb: 3 }}>
        <Grid item xs={12}>
          <Card sx={{ p: 2 }}>
            <Typography variant="h6" sx={{ mb: 2 }}>Stock Status Overview</Typography>
            {chartData.map((item) => {
              const total = Math.max(overview.totalProducts || 1, 1);
              const percent = Math.round((item.value / total) * 100);
              return (
                <div key={item.name} style={{ marginBottom: 14 }}>
                  <Typography variant="body2" sx={{ mb: 0.5 }}>
                    {item.name}: {item.value} ({percent}%)
                  </Typography>
                  <LinearProgress variant="determinate" value={percent} color={item.color} />
                </div>
              );
            })}
          </Card>
        </Grid>
      </Grid>

      <Card sx={{ p: 2 }}>
        <Typography variant="h6" sx={{ mb: 2 }}>
          Low Stock Products
        </Typography>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Name</TableCell>
              <TableCell>SKU</TableCell>
              <TableCell>Quantity</TableCell>
              <TableCell>Unit</TableCell>
              <TableCell>Low Threshold</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {lowStockProducts.length ? (
              lowStockProducts.map((product) => (
                <TableRow key={product.id}>
                  <TableCell>{product.name}</TableCell>
                  <TableCell>{product.sku}</TableCell>
                  <TableCell>{product.quantity}</TableCell>
                  <TableCell>{product.unit}</TableCell>
                  <TableCell>{product.low_stock_threshold}</TableCell>
                </TableRow>
              ))
            ) : <TableRow><TableCell colSpan={5}><EmptyState label="No low stock products." /></TableCell></TableRow>}
          </TableBody>
        </Table>
      </Card>
      <AppSnackbar
        open={toast.open}
        severity={toast.severity}
        message={toast.message}
        onClose={() => setToast({ ...toast, open: false })}
      />
    </Container>
  );
};

export default Dashboard;
