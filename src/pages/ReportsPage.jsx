import React, { useEffect, useMemo, useState } from "react";
import {
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Container,
  Grid,
  LinearProgress,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import DownloadOutlinedIcon from "@mui/icons-material/DownloadOutlined";
import api from "../services/api";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const currency = new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" });

const downloadText = (filename, content, type = "text/csv") => {
  const blob = new Blob([content], { type });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
};

const ReportsPage = () => {
  const [inventoryValue, setInventoryValue] = useState(0);
  const [salesSummary, setSalesSummary] = useState({ totalSoldUnits: 0, estimatedRevenue: 0, sales: [] });
  const [products, setProducts] = useState([]);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });

  useEffect(() => {
    const load = async () => {
      try {
        const [inv, sales, productRes] = await Promise.all([
          api.get("/reports/inventory"),
          api.get("/reports/sales"),
          api.get("/products"),
        ]);
        setInventoryValue(inv.data?.inventoryValue || 0);
        setSalesSummary(sales.data || { totalSoldUnits: 0, estimatedRevenue: 0, sales: [] });
        setProducts(Array.isArray(productRes.data) ? productRes.data : []);
      } catch {
        setToast({ open: true, severity: "error", message: "Failed to load reports." });
      }
    };
    load();
  }, []);

  const sales = useMemo(() => salesSummary.sales || [], [salesSummary.sales]);

  const analytics = useMemo(() => {
    const lowStock = products.filter((product) => product.stock_status === "low").length;
    const outOfStock = products.filter((product) => Number(product.quantity || 0) <= 0).length;
    const stockValue = products.reduce((sum, product) => sum + Number(product.quantity || 0) * Number(product.buying_price || 0), 0) || inventoryValue;
    const revenueByProduct = sales.reduce((acc, row) => {
      const name = row.product?.name || "Unknown";
      const revenue = Number(row.quantity || 0) * Number(row.product?.selling_price || 0);
      acc[name] = (acc[name] || 0) + revenue;
      return acc;
    }, {});
    const topProducts = Object.entries(revenueByProduct)
      .map(([name, revenue]) => ({ name, revenue }))
      .sort((a, b) => b.revenue - a.revenue)
      .slice(0, 5);
    const turnover = stockValue ? Number(salesSummary.estimatedRevenue || 0) / stockValue : 0;
    const forecastUnits = Math.ceil(Number(salesSummary.totalSoldUnits || 0) * 1.15);

    return { lowStock, outOfStock, stockValue, topProducts, turnover, forecastUnits };
  }, [inventoryValue, products, sales, salesSummary]);

  const exportCsv = () => {
    const rows = [
      ["Product", "Quantity", "Type", "Created At"],
      ...sales.map((row) => [row.product?.name || "-", row.quantity, row.type, row.created_at]),
    ];
    downloadText("sales-report.csv", rows.map((row) => row.join(",")).join("\n"));
  };

  const exportPdf = () => {
    const html = `
      <html>
        <head><title>AIMS Report</title></head>
        <body style="font-family: Arial; margin: 40px;">
          <h1>AIMS Inventory Report</h1>
          <p>Total stock value: ${currency.format(analytics.stockValue)}</p>
          <p>Sales revenue: ${currency.format(Number(salesSummary.estimatedRevenue || 0))}</p>
          <p>Low stock: ${analytics.lowStock}</p>
          <p>Forecast units next month: ${analytics.forecastUnits}</p>
        </body>
      </html>
    `;
    const win = window.open("", "_blank", "width=900,height=700");
    if (!win) {
      setToast({ open: true, severity: "warning", message: "Popup blocked. Allow popups to export PDF." });
      return;
    }
    win.document.write(html);
    win.document.close();
    win.print();
  };

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      <Box sx={{ display: "flex", justifyContent: "space-between", gap: 2, flexWrap: "wrap", mb: 3 }}>
        <Box>
          <Typography variant="overline" color="text.secondary">Analytics center</Typography>
          <Typography variant="h4" sx={{ fontWeight: 700 }}>Reports</Typography>
        </Box>
        <Box sx={{ display: "flex", gap: 1 }}>
          <Button startIcon={<DownloadOutlinedIcon />} variant="outlined" onClick={exportCsv}>CSV</Button>
          <Button startIcon={<DownloadOutlinedIcon />} variant="outlined" onClick={() => downloadText("sales-report.xls", "AIMS Sales Report\n" + JSON.stringify(sales, null, 2), "application/vnd.ms-excel")}>Excel</Button>
          <Button startIcon={<DownloadOutlinedIcon />} variant="contained" onClick={exportPdf}>PDF</Button>
        </Box>
      </Box>

      <Grid container spacing={2} sx={{ mb: 3 }}>
        {[
          ["Total Stock Value", currency.format(analytics.stockValue)],
          ["Items", products.length],
          ["Low Stock", analytics.lowStock],
          ["Out of Stock", analytics.outOfStock],
          ["Sales Revenue", currency.format(Number(salesSummary.estimatedRevenue || 0))],
          ["Forecast Need", `${analytics.forecastUnits} units`],
        ].map(([label, value]) => (
          <Grid item xs={12} sm={6} lg={2} key={label}>
            <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider" }}>
              <CardContent>
                <Typography color="text.secondary" variant="body2">{label}</Typography>
                <Typography variant="h5" sx={{ fontWeight: 700 }}>{value}</Typography>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>

      <Grid container spacing={2} sx={{ mb: 3 }}>
        <Grid item xs={12} md={6}>
          <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>Top Products</Typography>
              {analytics.topProducts.map((item) => {
                const max = Math.max(...analytics.topProducts.map((product) => product.revenue), 1);
                const percent = Math.round((item.revenue / max) * 100);
                return (
                  <Box key={item.name} sx={{ mb: 2 }}>
                    <Box sx={{ display: "flex", justifyContent: "space-between", mb: 0.75 }}>
                      <Typography>{item.name}</Typography>
                      <Typography color="text.secondary">{currency.format(item.revenue)}</Typography>
                    </Box>
                    <LinearProgress variant="determinate" value={percent} sx={{ height: 8, borderRadius: 4 }} />
                  </Box>
                );
              })}
              {!analytics.topProducts.length && <EmptyState label="No product revenue data yet." />}
            </CardContent>
          </Card>
        </Grid>
        <Grid item xs={12} md={6}>
          <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>ABC Analysis</Typography>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Class</TableCell>
                    <TableCell>Meaning</TableCell>
                    <TableCell align="right">Products</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {["A", "B", "C"].map((tier, index) => (
                    <TableRow key={tier}>
                      <TableCell><Chip size="small" label={tier} color={tier === "A" ? "success" : tier === "B" ? "primary" : "default"} /></TableCell>
                      <TableCell>{tier === "A" ? "High value / highest priority" : tier === "B" ? "Medium value / monitor weekly" : "Low value / replenish normally"}</TableCell>
                      <TableCell align="right">{Math.ceil(products.length / 3) - (index === 2 ? 1 : 0)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <Typography sx={{ mt: 2 }} color="text.secondary">
                Inventory turnover ratio: {analytics.turnover.toFixed(2)}
              </Typography>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card sx={{ border: "1px solid", borderColor: "divider" }}>
        <CardContent>
          <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>Sales Detail</Typography>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Product</TableCell>
                <TableCell>Quantity</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>Created At</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {sales.map((row) => (
                <TableRow key={row.id}>
                  <TableCell>{row.product?.name || "-"}</TableCell>
                  <TableCell>{row.quantity}</TableCell>
                  <TableCell>{row.type}</TableCell>
                  <TableCell>{row.created_at}</TableCell>
                </TableRow>
              ))}
              {!sales.length && (
                <TableRow>
                  <TableCell colSpan={4}><EmptyState label="No sales data yet." /></TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <AppSnackbar open={toast.open} severity={toast.severity} message={toast.message} onClose={() => setToast({ ...toast, open: false })} />
    </Container>
  );
};

export default ReportsPage;
