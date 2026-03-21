import React, { useEffect, useState } from "react";
import { Container, Typography, Table, TableHead, TableRow, TableCell, TableBody } from "@mui/material";
import api from "../services/api";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const InventoryPage = () => {
  const [products, setProducts] = useState([]);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });

  const fetchInventory = async () => {
    try {
      const res = await api.get("/inventory");
      setProducts((res.data || []).filter((p) => p.stock_status === "low"));
    } catch {
      setToast({ open: true, severity: "error", message: "Failed to fetch inventory." });
    }
  };

  useEffect(() => {
    fetchInventory();
  }, []);

  return (
    <Container>
      <Typography variant="h4" sx={{ mb: 2 }}>Low Stock Products</Typography>
      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Name</TableCell>
            <TableCell>Quantity</TableCell>
            <TableCell>Low Stock Threshold</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {products.map((product) => (
            <TableRow key={product.id}>
              <TableCell>{product.name}</TableCell>
              <TableCell>{product.quantity}</TableCell>
              <TableCell>{product.low_stock_threshold}</TableCell>
            </TableRow>
          ))}
          {!products.length && (
            <TableRow>
              <TableCell colSpan={3}><EmptyState label="No low stock products." /></TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
      <AppSnackbar open={toast.open} severity={toast.severity} message={toast.message} onClose={() => setToast({ ...toast, open: false })} />
    </Container>
  );
};

export default InventoryPage;
