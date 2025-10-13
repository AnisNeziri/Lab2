import React, { useEffect, useState } from "react";
import { Container, Typography, Table, TableHead, TableRow, TableCell, TableBody, Button } from "@mui/material";
import api from "../services/api";

const TransactionsPage = () => {
  const [transactions, setTransactions] = useState([]);

  const fetchTransactions = async () => {
    try {
      const res = await api.get("/transactions");
      setTransactions(res.data);
    } catch (err) {
      console.error("Failed to fetch transactions", err);
    }
  };

  useEffect(() => {
    fetchTransactions();
  }, []);

  return (
    <Container>
      <Typography variant="h4" sx={{ mb: 2 }}>Transactions</Typography>
      <Button variant="contained" sx={{ mb: 2 }}>Add Transaction</Button>
      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Product</TableCell>
            <TableCell>Quantity</TableCell>
            <TableCell>Type</TableCell>
            <TableCell>Date</TableCell>
            <TableCell>Notes</TableCell>
            <TableCell>Actions</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {transactions.map(tx => (
            <TableRow key={tx.id}>
              <TableCell>{tx.product?.name}</TableCell>
              <TableCell>{tx.quantity}</TableCell>
              <TableCell>{tx.type}</TableCell>
              <TableCell>{tx.date}</TableCell>
              <TableCell>{tx.notes}</TableCell>
              <TableCell>
                <Button size="small">Edit</Button>
                <Button size="small" color="error">Delete</Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Container>
  );
};

export default TransactionsPage;
