import React, { useEffect, useState } from "react";
import { Box, Button, Container, Dialog, DialogActions, DialogContent, DialogTitle, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@mui/material";
import api from "../services/api";
import AppSnackbar from "../components/AppSnackbar";
import EmptyState from "../components/EmptyState";

const SuppliersPage = () => {
  const [suppliers, setSuppliers] = useState([]);
  const [open, setOpen] = useState(false);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });
  const [form, setForm] = useState({ name: "", phone: "", email: "", address: "" });

  const fetchSuppliers = async () => {
    try {
      const res = await api.get("/suppliers");
      setSuppliers(res.data);
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to fetch suppliers." });
    }
  };

  useEffect(() => {
    fetchSuppliers();
  }, []);

  const handleCreate = async () => {
    try {
      await api.post("/suppliers", form);
      setForm({ name: "", phone: "", email: "", address: "" });
      setOpen(false);
      setToast({ open: true, severity: "success", message: "Supplier added successfully." });
      fetchSuppliers();
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to add supplier." });
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
    <Container>
      <Typography variant="h4" sx={{ mb: 2 }}>Suppliers</Typography>
      <Button variant="contained" sx={{ mb: 2 }} onClick={() => setOpen(true)}>Add Supplier</Button>
      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Name</TableCell>
            <TableCell>Email</TableCell>
            <TableCell>Phone</TableCell>
            <TableCell>Address</TableCell>
            <TableCell>Actions</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {suppliers.map((supplier) => (
            <TableRow key={supplier.id}>
              <TableCell>{supplier.name}</TableCell>
              <TableCell>{supplier.email}</TableCell>
              <TableCell>{supplier.phone}</TableCell>
              <TableCell>{supplier.address}</TableCell>
              <TableCell>
                <Button size="small" color="error" onClick={() => handleDelete(supplier.id)}>Delete</Button>
              </TableCell>
            </TableRow>
          ))}
          {!suppliers.length && (
            <TableRow>
              <TableCell colSpan={5}><EmptyState label="No suppliers found." /></TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <DialogTitle>Add Supplier</DialogTitle>
        <DialogContent>
          <Box sx={{ display: "grid", gap: 2, mt: 1 }}>
            <TextField label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            <TextField label="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            <TextField label="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            <TextField label="Address" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button onClick={handleCreate} variant="contained">Save</Button>
        </DialogActions>
      </Dialog>
      <AppSnackbar open={toast.open} severity={toast.severity} message={toast.message} onClose={() => setToast({ ...toast, open: false })} />
    </Container>
  );
};

export default SuppliersPage;
