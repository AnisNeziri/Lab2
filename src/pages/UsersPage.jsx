import React, { useEffect, useState } from "react";
import {
  Box,
  Button,
  Container,
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

const UsersPage = () => {
  const [users, setUsers] = useState([]);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });
  const [form, setForm] = useState({
    name: "",
    email: "",
    password: "",
    phone: "",
  });

  const fetchUsers = async () => {
    try {
      const res = await api.get("/users");
      setUsers(res.data);
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to fetch staff." });
    }
  };

  useEffect(() => {
    fetchUsers();
  }, []);

  const handleCreate = async (e) => {
    e.preventDefault();
    try {
      await api.post("/users", form);
      setForm({ name: "", email: "", password: "", phone: "" });
      setToast({ open: true, severity: "success", message: "Staff created successfully." });
      fetchUsers();
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to create staff user." });
    }
  };

  return (
    <Container sx={{ mt: 4 }}>
      <Typography variant="h4" sx={{ mb: 2 }}>Staff Management</Typography>

      <Box component="form" onSubmit={handleCreate} sx={{ display: "grid", gap: 2, mb: 3, maxWidth: 500 }}>
        <TextField label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
        <TextField label="Email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
        <TextField label="Password" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required />
        <TextField label="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
        <Button variant="contained" type="submit">Create Admin Staff</Button>
      </Box>

      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Name</TableCell>
            <TableCell>Email</TableCell>
            <TableCell>Role</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {users.map((u) => (
            <TableRow key={u.id}>
              <TableCell>{u.name}</TableCell>
              <TableCell>{u.email}</TableCell>
              <TableCell>{u.role}</TableCell>
            </TableRow>
          ))}
          {!users.length && (
            <TableRow>
              <TableCell colSpan={3}><EmptyState label="No staff users found." /></TableCell>
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

export default UsersPage;
