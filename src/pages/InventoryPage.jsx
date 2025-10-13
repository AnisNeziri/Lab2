import React, { useEffect, useState } from "react";
import { Container, Typography, Table, TableHead, TableRow, TableCell, TableBody, Button } from "@mui/material";
import api from "../services/api";

const InventoryPage = () => {
  const [products, setProducts] = useState([]);

  const fetchInventory = async () => {
    try {
      const res = await api.get("/inventory/low-stock");
      setProducts(res.data);
    } catch (err) {
      console.error("Failed to fetch inventory", err);
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
            <TableCell>Reorder Level</TableCell>
            <TableCell>Actions</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {products.map(product => (
            <TableRow key={product.id}>
              <TableCell>{product.name}</TableCell>
              <TableCell>{product.quantity}</TableCell>
              <TableCell>{product.reorder_level}</TableCell>
              <TableCell>
                <Button size="small">Adjust Stock</Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Container>
  );
};

export default InventoryPage;
