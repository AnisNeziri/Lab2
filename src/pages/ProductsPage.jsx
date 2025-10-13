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
  Select,
  MenuItem,
  Box,
  Alert,
} from "@mui/material";
import api from "../services/api";

const ProductsPage = () => {
  const [products, setProducts] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [loading, setLoading] = useState(false);
  const [banner, setBanner] = useState(null);

  const [openModal, setOpenModal] = useState(false);
  const [editingProduct, setEditingProduct] = useState(null);
  const [formData, setFormData] = useState({
    name: "",
    category: "",
    price: "",
    quantity: "",
    supplier_id: "",
  });

  // Fetch products and suppliers
  const fetchProducts = async () => {
    try {
      const res = await api.get("/products");
      setProducts(res.data);
    } catch {
      setBanner({ type: "error", text: "Failed to fetch products." });
    }
  };

  const fetchSuppliers = async () => {
    try {
      const res = await api.get("/suppliers");
      setSuppliers(res.data);
    } catch {
      setBanner({ type: "error", text: "Failed to fetch suppliers." });
    }
  };

  useEffect(() => {
    fetchProducts();
    fetchSuppliers();
  }, []);

  const openAddModal = () => {
    setFormData({ name: "", category: "", price: "", quantity: "", supplier_id: "" });
    setEditingProduct(null);
    setOpenModal(true);
  };

  const openEditModal = (product) => {
    setFormData({ ...product });
    setEditingProduct(product);
    setOpenModal(true);
  };

  const handleClose = () => setOpenModal(false);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async () => {
    setBanner(null);
    try {
      if (editingProduct) {
        // Update
        await api.put(`/products/${editingProduct.id}`, formData);
        setBanner({ type: "success", text: "Product updated successfully." });
      } else {
        // Create
        await api.post("/products", formData);
        setBanner({ type: "success", text: "Product added successfully." });
      }
      fetchProducts();
      setOpenModal(false);
    } catch (err) {
      setBanner({
        type: "error",
        text:
          err?.response?.data?.message ||
          "Operation failed. Please check your input.",
      });
    }
  };

  const handleDelete = async (id) => {
    if (!window.confirm("Are you sure you want to delete this product?")) return;
    try {
      await api.delete(`/products/${id}`);
      setBanner({ type: "success", text: "Product deleted successfully." });
      fetchProducts();
    } catch {
      setBanner({ type: "error", text: "Failed to delete product." });
    }
  };

  return (
    <Container sx={{ mt: 4 }}>
      <Box sx={{ display: "flex", justifyContent: "space-between", mb: 2 }}>
        <Typography variant="h4">Product Management</Typography>
        <Button variant="contained" onClick={openAddModal}>
          Add Product
        </Button>
      </Box>

      {banner && (
        <Alert severity={banner.type} sx={{ mb: 2 }}>
          {banner.text}
        </Alert>
      )}

      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Name</TableCell>
            <TableCell>Category</TableCell>
            <TableCell>Price</TableCell>
            <TableCell>Quantity</TableCell>
            <TableCell>Supplier</TableCell>
            <TableCell>Actions</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {products.map((p) => (
            <TableRow key={p.id}>
              <TableCell>{p.name}</TableCell>
              <TableCell>{p.category}</TableCell>
              <TableCell>${p.price}</TableCell>
              <TableCell>{p.quantity}</TableCell>
              <TableCell>
                {suppliers.find((s) => s.id === p.supplier_id)?.name || "-"}
              </TableCell>
              <TableCell>
                <Button
                  size="small"
                  variant="outlined"
                  sx={{ mr: 1 }}
                  onClick={() => openEditModal(p)}
                >
                  Edit
                </Button>
                <Button
                  size="small"
                  variant="outlined"
                  color="error"
                  onClick={() => handleDelete(p.id)}
                >
                  Delete
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      {/* Modal for Add/Edit */}
      <Dialog open={openModal} onClose={handleClose}>
        <DialogTitle>{editingProduct ? "Edit Product" : "Add Product"}</DialogTitle>
        <DialogContent sx={{ display: "flex", flexDirection: "column", gap: 2, mt: 1 }}>
          <TextField
            label="Name"
            name="name"
            value={formData.name}
            onChange={handleChange}
            fullWidth
          />
          <TextField
            label="Category"
            name="category"
            value={formData.category}
            onChange={handleChange}
            fullWidth
          />
          <TextField
            label="Price"
            name="price"
            type="number"
            value={formData.price}
            onChange={handleChange}
            fullWidth
          />
          <TextField
            label="Quantity"
            name="quantity"
            type="number"
            value={formData.quantity}
            onChange={handleChange}
            fullWidth
          />
          <Select
            name="supplier_id"
            value={formData.supplier_id}
            onChange={handleChange}
            displayEmpty
          >
            <MenuItem value="">
              <em>Select Supplier</em>
            </MenuItem>
            {suppliers.map((s) => (
              <MenuItem key={s.id} value={s.id}>
                {s.name}
              </MenuItem>
            ))}
          </Select>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleClose}>Cancel</Button>
          <Button variant="contained" onClick={handleSubmit}>
            {editingProduct ? "Update" : "Add"}
          </Button>
        </DialogActions>
      </Dialog>
    </Container>
  );
};

export default ProductsPage;
