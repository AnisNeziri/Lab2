import React, { useEffect, useState } from "react";
import { Card, CardContent, Container, Grid, Table, TableBody, TableCell, TableHead, TableRow, Typography } from "@mui/material";
import api from "../services/api";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const ReportsPage = () => {
  const [inventoryValue, setInventoryValue] = useState(0);
  const [salesSummary, setSalesSummary] = useState({ totalSoldUnits: 0, estimatedRevenue: 0, sales: [] });
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });

  useEffect(() => {
    const load = async () => {
      try {
        const [inv, sales] = await Promise.all([
          api.get("/reports/inventory"),
          api.get("/reports/sales"),
        ]);
        setInventoryValue(inv.data?.inventoryValue || 0);
        setSalesSummary(sales.data || { totalSoldUnits: 0, estimatedRevenue: 0, sales: [] });
      } catch {
        setToast({ open: true, severity: "error", message: "Failed to load reports." });
      }
    };
    load();
  }, []);

  return (
    <Container>
      <Typography variant="h4" sx={{ mb: 2 }}>Reports</Typography>
      <Grid container spacing={2} sx={{ mb: 2 }}>
        <Grid item xs={12} md={6}>
          <Card><CardContent>
            <Typography color="text.secondary">Inventory Value</Typography>
            <Typography variant="h5">${Number(inventoryValue).toFixed(2)}</Typography>
          </CardContent></Card>
        </Grid>
        <Grid item xs={12} md={6}>
          <Card><CardContent>
            <Typography color="text.secondary">Estimated Sales Revenue</Typography>
            <Typography variant="h5">${Number(salesSummary.estimatedRevenue || 0).toFixed(2)}</Typography>
            <Typography variant="body2">Units sold: {salesSummary.totalSoldUnits || 0}</Typography>
          </CardContent></Card>
        </Grid>
      </Grid>

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
          {(salesSummary.sales || []).map((row) => (
            <TableRow key={row.id}>
              <TableCell>{row.product?.name || "-"}</TableCell>
              <TableCell>{row.quantity}</TableCell>
              <TableCell>{row.type}</TableCell>
              <TableCell>{row.created_at}</TableCell>
            </TableRow>
          ))}
          {!(salesSummary.sales || []).length && (
            <TableRow>
              <TableCell colSpan={4}><EmptyState label="No sales data yet." /></TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
      <AppSnackbar open={toast.open} severity={toast.severity} message={toast.message} onClose={() => setToast({ ...toast, open: false })} />
    </Container>
  );
};

export default ReportsPage;
