import React, { useEffect, useState } from "react";
import {
  Box,
  Button,
  Container,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
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
import LoadingState from "../components/LoadingState";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const ProductsPage = () => {
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(false);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });
  const [openModal, setOpenModal] = useState(false);
  const [form, setForm] = useState({
    name: "",
    sku: "",
    quantity: 0,
    unit: "pcs",
    buying_price: 0,
    selling_price: 0,
    low_stock_threshold: 0,
    high_stock_threshold: 0,
  });

  const fetchProducts = async () => {
    setLoading(true);
    try {
      const res = await api.get("/products");
      setProducts(res.data);
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to fetch products." });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchProducts();
  }, []);

  const handleCreate = async () => {
    try {
      await api.post("/products", form);
      setOpenModal(false);
      setForm({
        name: "",
        sku: "",
        quantity: 0,
        unit: "pcs",
        buying_price: 0,
        selling_price: 0,
        low_stock_threshold: 0,
        high_stock_threshold: 0,
      });
      setToast({ open: true, severity: "success", message: "Product added successfully." });
      fetchProducts();
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to add product." });
    }
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/products/${id}`);
      setToast({ open: true, severity: "success", message: "Product deleted successfully." });
      fetchProducts();
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to delete product." });
    }
  };

  return (
    <Container>
      <Typography variant="h4" sx={{ mb: 2 }}>
        Products
      </Typography>
      <Button variant="contained" sx={{ mb: 2 }} onClick={() => setOpenModal(true)}>
        Add Product
      </Button>
      {loading ? <LoadingState label="Loading products..." /> : (
      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Name</TableCell>
            <TableCell>SKU</TableCell>
            <TableCell>Quantity</TableCell>
            <TableCell>Unit</TableCell>
            <TableCell>Buying Price</TableCell>
            <TableCell>Selling Price</TableCell>
            <TableCell>Actions</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {products.map((product) => (
            <TableRow key={product.id}>
              <TableCell>{product.name}</TableCell>
              <TableCell>{product.sku}</TableCell>
              <TableCell>{product.quantity}</TableCell>
              <TableCell>{product.unit}</TableCell>
              <TableCell>{product.buying_price}</TableCell>
              <TableCell>{product.selling_price}</TableCell>
              <TableCell>
                <Button size="small" color="error" onClick={() => handleDelete(product.id)}>
                  Delete
                </Button>
              </TableCell>
            </TableRow>
          ))}
          {!products.length && (
            <TableRow>
              <TableCell colSpan={7}><EmptyState label="No products found." /></TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
      )}

      <Dialog open={openModal} onClose={() => setOpenModal(false)} fullWidth maxWidth="sm">
        <DialogTitle>Add Product</DialogTitle>
        <DialogContent>
          <Box sx={{ display: "grid", gap: 2, mt: 1 }}>
            <TextField label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            <TextField label="SKU" value={form.sku} onChange={(e) => setForm({ ...form, sku: e.target.value })} />
            <TextField label="Quantity" type="number" value={form.quantity} onChange={(e) => setForm({ ...form, quantity: Number(e.target.value) })} />
            <TextField select label="Unit" value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })}>
              <MenuItem value="pcs">pcs</MenuItem>
              <MenuItem value="L">L</MenuItem>
              <MenuItem value="m">m</MenuItem>
            </TextField>
            <TextField label="Buying Price" type="number" value={form.buying_price} onChange={(e) => setForm({ ...form, buying_price: Number(e.target.value) })} />
            <TextField label="Selling Price" type="number" value={form.selling_price} onChange={(e) => setForm({ ...form, selling_price: Number(e.target.value) })} />
            <TextField label="Low Stock Threshold" type="number" value={form.low_stock_threshold} onChange={(e) => setForm({ ...form, low_stock_threshold: Number(e.target.value) })} />
            <TextField label="High Stock Threshold" type="number" value={form.high_stock_threshold} onChange={(e) => setForm({ ...form, high_stock_threshold: Number(e.target.value) })} />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpenModal(false)}>Cancel</Button>
          <Button variant="contained" onClick={handleCreate}>Save</Button>
        </DialogActions>
      </Dialog>
      <AppSnackbar
        open={toast.open}
        severity={toast.severity}
        message={toast.message}
        onClose={() => setToast({ ...toast, open: false })}
      />
    </Container>
  );
};

export default ProductsPage;
