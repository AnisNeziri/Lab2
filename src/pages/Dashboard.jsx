import React, { useEffect, useMemo, useState } from "react";
import {
  Alert,
  Box,
  Card,
  CardContent,
  Chip,
  Container,
  Grid,
  LinearProgress,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import Inventory2OutlinedIcon from "@mui/icons-material/Inventory2Outlined";
import PointOfSaleOutlinedIcon from "@mui/icons-material/PointOfSaleOutlined";
import ReceiptLongOutlinedIcon from "@mui/icons-material/ReceiptLongOutlined";
import WarningAmberOutlinedIcon from "@mui/icons-material/WarningAmberOutlined";
import api from "../services/api";
import LoadingState from "../components/LoadingState";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const currency = new Intl.NumberFormat("en-US", {
  style: "currency",
  currency: "USD",
});

const normalizeArray = (value) => (Array.isArray(value) ? value : []);

const Dashboard = () => {
  const [overview, setOverview] = useState(null);
  const [products, setProducts] = useState([]);
  const [transactions, setTransactions] = useState([]);
  const [salesSummary, setSalesSummary] = useState({ totalSoldUnits: 0, estimatedRevenue: 0, sales: [] });
  const [loading, setLoading] = useState(true);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });

  useEffect(() => {
    const loadDashboard = async () => {
      setLoading(true);
      const [overviewRes, productsRes, transactionsRes, salesRes] = await Promise.allSettled([
        api.get("/dashboard/overview"),
        api.get("/dashboard/products"),
        api.get("/transactions"),
        api.get("/reports/sales"),
      ]);

      if (overviewRes.status === "fulfilled") {
        setOverview(overviewRes.value.data);
      }

      if (productsRes.status === "fulfilled") {
        setProducts(normalizeArray(productsRes.value.data));
      }

      if (transactionsRes.status === "fulfilled") {
        setTransactions(normalizeArray(transactionsRes.value.data));
      }

      if (salesRes.status === "fulfilled") {
        setSalesSummary(salesRes.value.data || { totalSoldUnits: 0, estimatedRevenue: 0, sales: [] });
      }

      if ([overviewRes, productsRes, transactionsRes, salesRes].some((res) => res.status === "rejected")) {
        setToast({ open: true, severity: "warning", message: "Some dashboard widgets could not load." });
      }

      setLoading(false);
    };

    loadDashboard();
  }, []);

  const stats = useMemo(() => {
    const totalProducts = overview?.totalProducts ?? products.length;
    const lowStock = overview?.lowStock ?? products.filter((product) => product.stock_status === "low").length;
    const normalStock = overview?.normalStock ?? products.filter((product) => product.stock_status === "normal").length;
    const highStock = overview?.highStock ?? products.filter((product) => product.stock_status === "high").length;
    const estimatedRevenue = Number(salesSummary.estimatedRevenue || 0);
    const invoiceCount = normalizeArray(salesSummary.sales).length || transactions.filter((tx) => tx.type === "sale").length;

    return { totalProducts, lowStock, normalStock, highStock, estimatedRevenue, invoiceCount };
  }, [overview, products, salesSummary, transactions]);

  const lowStockProducts = useMemo(
    () => products.filter((product) => product.stock_status === "low").slice(0, 6),
    [products]
  );

  const recentActivity = useMemo(
    () => transactions.slice(0, 6),
    [transactions]
  );

  const chartData = [
    { name: "Low stock", value: stats.lowStock, color: "error" },
    { name: "Normal stock", value: stats.normalStock, color: "primary" },
    { name: "High stock", value: stats.highStock, color: "success" },
  ];

  if (loading) {
    return (
      <Container sx={{ mt: 4 }}>
        <LoadingState label="Loading dashboard..." />
      </Container>
    );
  }

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      <Box sx={{ display: "flex", justifyContent: "space-between", gap: 2, flexWrap: "wrap", mb: 3 }}>
        <Box>
          <Typography variant="overline" color="text.secondary">
            Operations overview
          </Typography>
          <Typography variant="h4" sx={{ fontWeight: 700 }}>
            Dashboard
          </Typography>
        </Box>
        <Chip
          icon={<WarningAmberOutlinedIcon />}
          color={stats.lowStock ? "warning" : "success"}
          label={stats.lowStock ? `${stats.lowStock} products need attention` : "Stock levels are healthy"}
          variant="outlined"
        />
      </Box>

      <Grid container spacing={2} sx={{ mb: 3 }}>
        {[
          { label: "Total Products", value: stats.totalProducts, helper: "Active catalog items", icon: <Inventory2OutlinedIcon />, color: "#2563eb" },
          { label: "Low Stock", value: stats.lowStock, helper: "Reorder priority", icon: <WarningAmberOutlinedIcon />, color: "#d97706" },
          { label: "Sales Revenue", value: currency.format(stats.estimatedRevenue), helper: "Estimated from sales", icon: <PointOfSaleOutlinedIcon />, color: "#059669" },
          { label: "Invoices", value: stats.invoiceCount, helper: "Generated from sales", icon: <ReceiptLongOutlinedIcon />, color: "#7c3aed" },
        ].map((item) => (
          <Grid item xs={12} sm={6} lg={3} key={item.label}>
            <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider", boxShadow: "0 10px 28px rgba(15,23,42,0.08)" }}>
              <CardContent>
                <Box sx={{ display: "flex", alignItems: "center", justifyContent: "space-between", mb: 2 }}>
                  <Box sx={{ color: item.color, display: "flex" }}>{item.icon}</Box>
                  <Chip size="small" label={item.helper} variant="outlined" />
                </Box>
                <Typography color="text.secondary" variant="body2">
                  {item.label}
                </Typography>
                <Typography variant="h4" sx={{ fontWeight: 700, mt: 0.5 }}>
                  {item.value}
                </Typography>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>

      {stats.lowStock > 0 && (
        <Alert severity="warning" sx={{ mb: 3 }}>
          Review low-stock products before confirming more sales. The invoice page will still show sales, but stock should stay accurate.
        </Alert>
      )}

      <Grid container spacing={2} sx={{ mb: 3 }}>
        <Grid item xs={12} md={5}>
          <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>
                Stock Status
              </Typography>
              <Stack spacing={2}>
                {chartData.map((item) => {
                  const total = Math.max(stats.totalProducts || 1, 1);
                  const percent = Math.round((item.value / total) * 100);
                  return (
                    <Box key={item.name}>
                      <Box sx={{ display: "flex", justifyContent: "space-between", mb: 0.75 }}>
                        <Typography variant="body2">{item.name}</Typography>
                        <Typography variant="body2" color="text.secondary">
                          {item.value} ({percent}%)
                        </Typography>
                      </Box>
                      <LinearProgress variant="determinate" value={percent} color={item.color} sx={{ height: 8, borderRadius: 4 }} />
                    </Box>
                  );
                })}
              </Stack>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} md={7}>
          <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>
                Recent Activity
              </Typography>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Product</TableCell>
                    <TableCell>Type</TableCell>
                    <TableCell align="right">Quantity</TableCell>
                    <TableCell>Notes</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {recentActivity.map((tx) => (
                    <TableRow key={tx.id}>
                      <TableCell>{tx.product?.name || "-"}</TableCell>
                      <TableCell>
                        <Chip
                          size="small"
                          label={tx.type}
                          color={tx.type === "sale" ? "success" : "primary"}
                          variant="outlined"
                        />
                      </TableCell>
                      <TableCell align="right">{tx.quantity}</TableCell>
                      <TableCell>{tx.notes || "-"}</TableCell>
                    </TableRow>
                  ))}
                  {!recentActivity.length && (
                    <TableRow>
                      <TableCell colSpan={4}>
                        <EmptyState label="No recent activity yet." />
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card sx={{ border: "1px solid", borderColor: "divider" }}>
        <CardContent>
          <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>
            Low Stock Products
          </Typography>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Name</TableCell>
                <TableCell>SKU</TableCell>
                <TableCell align="right">Quantity</TableCell>
                <TableCell>Unit</TableCell>
                <TableCell align="right">Low Threshold</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {lowStockProducts.length ? (
                lowStockProducts.map((product) => (
                  <TableRow key={product.id}>
                    <TableCell>{product.name}</TableCell>
                    <TableCell>{product.sku}</TableCell>
                    <TableCell align="right">{product.quantity}</TableCell>
                    <TableCell>{product.unit}</TableCell>
                    <TableCell align="right">{product.low_stock_threshold}</TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={5}>
                    <EmptyState label="No low stock products." />
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
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
