import React, { useEffect, useState } from "react";
import {
  Box,
  Button,
  Container,
  MenuItem,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import api from "../services/api";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const TransactionsPage = () => {
  const [transactions, setTransactions] = useState([]);
  const [products, setProducts] = useState([]);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });
  const [form, setForm] = useState({
    product_id: "",
    quantity: 1,
    type: "purchase",
    notes: "",
  });

  const fetchTransactions = async () => {
    try {
      const res = await api.get("/transactions");
      setTransactions(res.data);
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to fetch transactions." });
    }
  };

  const fetchProducts = async () => {
    try {
      const res = await api.get("/products");
      setProducts(res.data || []);
    } catch {
      setProducts([]);
    }
  };

  useEffect(() => {
    fetchTransactions();
    fetchProducts();
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    const endpoint = form.type === "purchase" ? "/transactions/purchase" : "/transactions/sale";
    try {
      await api.post(endpoint, {
        product_id: Number(form.product_id),
        quantity: Number(form.quantity),
        notes: form.notes,
      });
      setToast({ open: true, severity: "success", message: "Transaction saved and stock updated." });
      setForm({ product_id: "", quantity: 1, type: "purchase", notes: "" });
      fetchTransactions();
      fetchProducts();
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to save transaction." });
    }
  };

  return (
    <Container>
      <Typography variant="h4" sx={{ mb: 2 }}>Transactions</Typography>

      <Box component="form" onSubmit={handleSubmit} sx={{ display: "grid", gap: 2, mb: 3, maxWidth: 500 }}>
        <TextField
          select
          label="Product"
          value={form.product_id}
          onChange={(e) => setForm({ ...form, product_id: e.target.value })}
          required
        >
          {products.map((p) => (
            <MenuItem key={p.id} value={p.id}>
              {p.name} ({p.sku}) - Qty: {p.quantity}
            </MenuItem>
          ))}
        </TextField>
        <TextField
          select
          label="Type"
          value={form.type}
          onChange={(e) => setForm({ ...form, type: e.target.value })}
        >
          <MenuItem value="purchase">Purchase</MenuItem>
          <MenuItem value="sale">Sale</MenuItem>
        </TextField>
        <TextField
          type="number"
          label="Quantity"
          value={form.quantity}
          onChange={(e) => setForm({ ...form, quantity: e.target.value })}
          inputProps={{ min: 1 }}
          required
        />
        <TextField
          label="Notes"
          value={form.notes}
          onChange={(e) => setForm({ ...form, notes: e.target.value })}
        />
        <Button variant="contained" type="submit">Save Transaction</Button>
      </Box>

      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Product</TableCell>
            <TableCell>Quantity</TableCell>
            <TableCell>Type</TableCell>
            <TableCell>Notes</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {transactions.map((tx) => (
            <TableRow key={tx.id}>
              <TableCell>{tx.product?.name}</TableCell>
              <TableCell>{tx.quantity}</TableCell>
              <TableCell>{tx.type}</TableCell>
              <TableCell>{tx.notes}</TableCell>
            </TableRow>
          ))}
          {!transactions.length && (
            <TableRow>
              <TableCell colSpan={4}><EmptyState label="No transactions yet." /></TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
      <AppSnackbar
        open={toast.open}
        severity={toast.severity}
        message={toast.message}
        onClose={() => setToast({ ...toast, open: false })}
      />
    </Container>
  );
};

export default TransactionsPage;
