import React, { useEffect, useState } from "react";
import {
  Container,
  Typography,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Button,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  Box,
  Alert,
} from "@mui/material";
import api from "../services/api";

const InventoryPage = () => {
  const [products, setProducts] = useState([]);
  const [banner, setBanner] = useState(null);
  const [openModal, setOpenModal] = useState(false);
  const [adjustProduct, setAdjustProduct] = useState(null);
  const [adjustQty, setAdjustQty] = useState("");

  const fetchProducts = async () => {
    try {
      const res = await api.get("/inventory/low-stock"); // or /products for all
      setProducts(res.data);
    } catch {
      setBanner({ type: "error", text: "Failed to fetch products." });
    }
  };

  useEffect(() => {
    fetchProducts();
  }, []);

  const handleOpenModal = (product) => {
    setAdjustProduct(product);
    setAdjustQty("");
    setOpenModal(true);
  };

  const handleClose = () => setOpenModal(false);

  const handleAdjust = async () => {
    try {
      await api.post(`/inventory/adjust/${adjustProduct.id}`, { quantity: adjustQty });
      setBanner({ type: "success", text: "Stock updated successfully." });
      fetchProducts();
      setOpenModal(false);
    } catch {
      setBanner({ type: "error", text: "Failed to update stock." });
    }
  };

  return (
    <Container sx={{ mt: 4 }}>
      <Typography variant="h4" sx={{ mb: 2 }}>
        Inventory Management
      </Typography>

      {banner && (
        <Alert severity={banner.type} sx={{ mb: 2 }}>
          {banner.text}
        </Alert>
      )}

      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Product</TableCell>
            <TableCell>Category</TableCell>
            <TableCell>Quantity</TableCell>
            <TableCell>Actions</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {products.map((p) => (
            <TableRow key={p.id}>
              <TableCell>{p.name}</TableCell>
              <TableCell>{p.category}</TableCell>
              <TableCell>{p.quantity}</TableCell>
              <TableCell>
                <Button
                  size="small"
                  variant="outlined"
                  onClick={() => handleOpenModal(p)}
                >
                  Adjust Stock
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      <Dialog open={openModal} onClose={handleClose}>
        <DialogTitle>Adjust Stock for {adjustProduct?.name}</DialogTitle>
        <DialogContent sx={{ mt: 1 }}>
          <TextField
            label="Quantity"
            type="number"
            value={adjustQty}
            onChange={(e) => setAdjustQty(e.target.value)}
            fullWidth
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={handleClose}>Cancel</Button>
          <Button variant="contained" onClick={handleAdjust}>
            Update Stock
          </Button>
        </DialogActions>
      </Dialog>
    </Container>
  );
};

export default InventoryPage;
