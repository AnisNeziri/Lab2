import React, { useEffect, useMemo, useState } from "react";
import {
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Container,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Grid,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import api from "../services/api";
import AppSnackbar from "../components/AppSnackbar";
import EmptyState from "../components/EmptyState";

const emptyForm = {
  name: "",
  phone: "",
  email: "",
  address: "",
  contact_person: "",
  category: "",
  payment_terms: "Net 30",
  rating: 4,
};

const SuppliersPage = () => {
  const [suppliers, setSuppliers] = useState([]);
  const [open, setOpen] = useState(false);
  const [editingSupplier, setEditingSupplier] = useState(null);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });
  const [form, setForm] = useState(emptyForm);

  const fetchSuppliers = async () => {
    try {
      const res = await api.get("/suppliers");
      setSuppliers(Array.isArray(res.data) ? res.data : []);
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to fetch suppliers." });
    }
  };

  useEffect(() => {
    fetchSuppliers();
  }, []);

  const metrics = useMemo(() => {
    const active = suppliers.length;
    const emailReady = suppliers.filter((supplier) => supplier.email).length;
    const avgRating = suppliers.length
      ? suppliers.reduce((sum, supplier) => sum + Number(supplier.rating || 4), 0) / suppliers.length
      : 0;

    return { active, emailReady, avgRating };
  }, [suppliers]);

  const openCreate = () => {
    setEditingSupplier(null);
    setForm(emptyForm);
    setOpen(true);
  };

  const openEdit = (supplier) => {
    setEditingSupplier(supplier);
    setForm({ ...emptyForm, ...supplier });
    setOpen(true);
  };

  const handleSave = async () => {
    try {
      if (editingSupplier) {
        await api.put(`/suppliers/${editingSupplier.id}`, form);
        setToast({ open: true, severity: "success", message: "Supplier updated successfully." });
      } else {
        await api.post("/suppliers", form);
        setToast({ open: true, severity: "success", message: "Supplier added successfully." });
      }
      setOpen(false);
      setEditingSupplier(null);
      setForm(emptyForm);
      fetchSuppliers();
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to save supplier." });
    }
  };

  const handleDelete = async (id) => {
    try {
      await api.delete(`/suppliers/${id}`);
      setToast({ open: true, severity: "success", message: "Supplier deleted." });
      fetchSuppliers();
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to delete supplier." });
    }
  };

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      <Box sx={{ display: "flex", justifyContent: "space-between", gap: 2, flexWrap: "wrap", mb: 3 }}>
        <Box>
          <Typography variant="overline" color="text.secondary">Procurement network</Typography>
          <Typography variant="h4" sx={{ fontWeight: 700 }}>Suppliers</Typography>
        </Box>
        <Button variant="contained" onClick={openCreate}>Add Supplier</Button>
      </Box>

      <Grid container spacing={2} sx={{ mb: 3 }}>
        {[
          ["Active suppliers", metrics.active],
          ["Email ready", metrics.emailReady],
          ["Average rating", metrics.avgRating.toFixed(1)],
        ].map(([label, value]) => (
          <Grid item xs={12} md={4} key={label}>
            <Card sx={{ border: "1px solid", borderColor: "divider" }}>
              <CardContent>
                <Typography color="text.secondary">{label}</Typography>
                <Typography variant="h4" sx={{ fontWeight: 700 }}>{value}</Typography>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>

      <Card sx={{ border: "1px solid", borderColor: "divider" }}>
        <CardContent>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Name</TableCell>
                <TableCell>Contact</TableCell>
                <TableCell>Category</TableCell>
                <TableCell>Terms</TableCell>
                <TableCell>Rating</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {suppliers.map((supplier) => (
                <TableRow key={supplier.id}>
                  <TableCell>
                    <Typography sx={{ fontWeight: 700 }}>{supplier.name}</Typography>
                    <Typography variant="body2" color="text.secondary">{supplier.address || "-"}</Typography>
                  </TableCell>
                  <TableCell>
                    <Typography>{supplier.contact_person || supplier.phone || "-"}</Typography>
                    <Typography variant="body2" color="text.secondary">{supplier.email || "-"}</Typography>
                  </TableCell>
                  <TableCell>{supplier.category || "General"}</TableCell>
                  <TableCell>{supplier.payment_terms || "Net 30"}</TableCell>
                  <TableCell>
                    <Chip size="small" label={`${supplier.rating || 4}/5`} color="primary" variant="outlined" />
                  </TableCell>
                  <TableCell align="right">
                    <Button size="small" onClick={() => openEdit(supplier)}>Edit</Button>
                    <Button size="small" color="error" onClick={() => handleDelete(supplier.id)}>Delete</Button>
                  </TableCell>
                </TableRow>
              ))}
              {!suppliers.length && (
                <TableRow>
                  <TableCell colSpan={6}><EmptyState label="No suppliers found." /></TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <DialogTitle>{editingSupplier ? "Edit Supplier" : "Add Supplier"}</DialogTitle>
        <DialogContent>
          <Box sx={{ display: "grid", gap: 2, mt: 1 }}>
            <TextField label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            <TextField label="Contact Person" value={form.contact_person} onChange={(e) => setForm({ ...form, contact_person: e.target.value })} />
            <TextField label="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            <TextField label="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            <TextField label="Category" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
            <TextField label="Payment Terms" value={form.payment_terms} onChange={(e) => setForm({ ...form, payment_terms: e.target.value })} />
            <TextField label="Rating" type="number" inputProps={{ min: 1, max: 5 }} value={form.rating} onChange={(e) => setForm({ ...form, rating: Number(e.target.value) })} />
            <TextField label="Address" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button onClick={handleSave} variant="contained">Save</Button>
        </DialogActions>
      </Dialog>

      <AppSnackbar open={toast.open} severity={toast.severity} message={toast.message} onClose={() => setToast({ ...toast, open: false })} />
    </Container>
  );
};

export default SuppliersPage;
