import React, { useEffect, useMemo, useState } from "react";
import {
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Container,
  Grid,
  MenuItem,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import PointOfSaleOutlinedIcon from "@mui/icons-material/PointOfSaleOutlined";
import ReplayOutlinedIcon from "@mui/icons-material/ReplayOutlined";
import api from "../services/api";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const salesKey = "aims_sales_orders";
const customersKey = "aims_customers";
const currencies = {
  USD: 1,
  EUR: 0.92,
  GBP: 0.78,
};

const money = (value, currency) =>
  new Intl.NumberFormat("en-US", { style: "currency", currency }).format(Number(value || 0));

const readStorage = (key) => {
  try {
    return JSON.parse(localStorage.getItem(key)) || [];
  } catch {
    return [];
  }
};

const writeStorage = (key, value) => localStorage.setItem(key, JSON.stringify(value));

const SalesPage = () => {
  const [products, setProducts] = useState([]);
  const [customers, setCustomers] = useState([]);
  const [sales, setSales] = useState([]);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });
  const [customerForm, setCustomerForm] = useState({ name: "", email: "", phone: "" });
  const [saleForm, setSaleForm] = useState({
    customer_id: "",
    product_id: "",
    quantity: 1,
    discount: 0,
    currency: "USD",
    type: "invoice",
  });

  useEffect(() => {
    setCustomers(readStorage(customersKey));
    setSales(readStorage(salesKey));
    const loadProducts = async () => {
      try {
        const res = await api.get("/products");
        setProducts(Array.isArray(res.data) ? res.data : []);
      } catch {
        setToast({ open: true, severity: "warning", message: "Products could not load." });
      }
    };
    loadProducts();
  }, []);

  const selectedProduct = products.find((product) => String(product.id) === String(saleForm.product_id));
  const selectedCustomer = customers.find((customer) => String(customer.id) === String(saleForm.customer_id));
  const basePrice = Number(selectedProduct?.selling_price || 0) * Number(saleForm.quantity || 0);
  const discountAmount = basePrice * (Number(saleForm.discount || 0) / 100);
  const totalUsd = Math.max(0, basePrice - discountAmount);
  const convertedTotal = totalUsd * currencies[saleForm.currency];

  const metrics = useMemo(() => {
    const invoices = sales.filter((sale) => sale.type === "invoice");
    const refunds = sales.filter((sale) => sale.type === "refund");
    const revenue = invoices.reduce((sum, sale) => sum + Number(sale.total_usd || 0), 0) - refunds.reduce((sum, sale) => sum + Number(sale.total_usd || 0), 0);
    return { customers: customers.length, invoices: invoices.length, revenue };
  }, [customers, sales]);

  const saveCustomers = (nextCustomers) => {
    setCustomers(nextCustomers);
    writeStorage(customersKey, nextCustomers);
  };

  const saveSales = (nextSales) => {
    setSales(nextSales);
    writeStorage(salesKey, nextSales);
  };

  const addCustomer = () => {
    if (!customerForm.name) return;
    saveCustomers([{ ...customerForm, id: Date.now(), created_at: new Date().toISOString() }, ...customers]);
    setCustomerForm({ name: "", email: "", phone: "" });
    setToast({ open: true, severity: "success", message: "Customer added." });
  };

  const createSale = async () => {
    if (!selectedProduct) return;
    const sale = {
      id: Date.now(),
      customer_name: selectedCustomer?.name || "Walk-in customer",
      product_id: selectedProduct.id,
      product_name: selectedProduct.name,
      quantity: Number(saleForm.quantity),
      discount: Number(saleForm.discount),
      currency: saleForm.currency,
      type: saleForm.type,
      total_usd: totalUsd,
      total_display: convertedTotal,
      created_at: new Date().toISOString(),
    };

    if (saleForm.type === "invoice") {
      try {
        await api.post("/transactions/sale", {
          product_id: Number(saleForm.product_id),
          quantity: Number(saleForm.quantity),
          notes: `${sale.customer_name} sale with ${sale.discount}% discount`,
        });
        setToast({ open: true, severity: "success", message: "Sale saved and stock updated." });
      } catch {
        setToast({ open: true, severity: "warning", message: "Sale saved locally. Backend stock update failed." });
      }
    } else {
      setToast({ open: true, severity: "success", message: `${saleForm.type} saved.` });
    }

    saveSales([sale, ...sales]);
    setSaleForm({ customer_id: "", product_id: "", quantity: 1, discount: 0, currency: "USD", type: "invoice" });
  };

  const refundSale = (sale) => {
    const refund = { ...sale, id: Date.now(), type: "refund", created_at: new Date().toISOString() };
    saveSales([refund, ...sales]);
    setToast({ open: true, severity: "success", message: "Refund recorded." });
  };

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      <Box sx={{ display: "flex", justifyContent: "space-between", gap: 2, flexWrap: "wrap", mb: 3 }}>
        <Box>
          <Typography variant="overline" color="text.secondary">Sales workspace</Typography>
          <Typography variant="h4" sx={{ fontWeight: 700 }}>Sales & POS</Typography>
        </Box>
        <Chip icon={<PointOfSaleOutlinedIcon />} label="Direct sale mode" color="success" variant="outlined" />
      </Box>

      <Grid container spacing={2} sx={{ mb: 3 }}>
        {[
          ["Customers", metrics.customers],
          ["Invoices", metrics.invoices],
          ["Net revenue", money(metrics.revenue, "USD")],
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

      <Grid container spacing={2} sx={{ mb: 3 }}>
        <Grid item xs={12} md={4}>
          <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>CRM Lite</Typography>
              <Stack spacing={2}>
                <TextField label="Customer Name" value={customerForm.name} onChange={(e) => setCustomerForm({ ...customerForm, name: e.target.value })} />
                <TextField label="Email" value={customerForm.email} onChange={(e) => setCustomerForm({ ...customerForm, email: e.target.value })} />
                <TextField label="Phone" value={customerForm.phone} onChange={(e) => setCustomerForm({ ...customerForm, phone: e.target.value })} />
                <Button variant="contained" onClick={addCustomer}>Add Customer</Button>
              </Stack>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} md={8}>
          <Card sx={{ border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>POS / Sales Order</Typography>
              <Grid container spacing={2}>
                <Grid item xs={12} md={6}>
                  <TextField fullWidth select label="Customer" value={saleForm.customer_id} onChange={(e) => setSaleForm({ ...saleForm, customer_id: e.target.value })}>
                    <MenuItem value="">Walk-in customer</MenuItem>
                    {customers.map((customer) => <MenuItem key={customer.id} value={customer.id}>{customer.name}</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid item xs={12} md={6}>
                  <TextField fullWidth select label="Document Type" value={saleForm.type} onChange={(e) => setSaleForm({ ...saleForm, type: e.target.value })}>
                    <MenuItem value="quotation">Quotation</MenuItem>
                    <MenuItem value="order">Sales Order</MenuItem>
                    <MenuItem value="invoice">Invoice</MenuItem>
                  </TextField>
                </Grid>
                <Grid item xs={12} md={6}>
                  <TextField fullWidth select label="Product" value={saleForm.product_id} onChange={(e) => setSaleForm({ ...saleForm, product_id: e.target.value })}>
                    {products.map((product) => <MenuItem key={product.id} value={product.id}>{product.name} - Qty {product.quantity}</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid item xs={6} md={3}>
                  <TextField fullWidth label="Quantity" type="number" inputProps={{ min: 1 }} value={saleForm.quantity} onChange={(e) => setSaleForm({ ...saleForm, quantity: e.target.value })} />
                </Grid>
                <Grid item xs={6} md={3}>
                  <TextField fullWidth label="Discount %" type="number" inputProps={{ min: 0, max: 100 }} value={saleForm.discount} onChange={(e) => setSaleForm({ ...saleForm, discount: e.target.value })} />
                </Grid>
                <Grid item xs={12} md={6}>
                  <TextField fullWidth select label="Currency" value={saleForm.currency} onChange={(e) => setSaleForm({ ...saleForm, currency: e.target.value })}>
                    {Object.keys(currencies).map((currency) => <MenuItem key={currency} value={currency}>{currency}</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid item xs={12} md={6}>
                  <Card sx={{ bgcolor: "grey.50", border: "1px solid", borderColor: "divider" }}>
                    <CardContent>
                      <Typography color="text.secondary">Total</Typography>
                      <Typography variant="h4" sx={{ fontWeight: 700 }}>{money(convertedTotal, saleForm.currency)}</Typography>
                    </CardContent>
                  </Card>
                </Grid>
              </Grid>
              <Button sx={{ mt: 2 }} variant="contained" onClick={createSale} disabled={!saleForm.product_id}>
                Save Document
              </Button>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card sx={{ border: "1px solid", borderColor: "divider" }}>
        <CardContent>
          <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>Sales History</Typography>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Customer</TableCell>
                <TableCell>Product</TableCell>
                <TableCell>Type</TableCell>
                <TableCell align="right">Qty</TableCell>
                <TableCell align="right">Discount</TableCell>
                <TableCell align="right">Total</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {sales.map((sale) => (
                <TableRow key={sale.id}>
                  <TableCell>{sale.customer_name}</TableCell>
                  <TableCell>{sale.product_name}</TableCell>
                  <TableCell><Chip size="small" label={sale.type} variant="outlined" /></TableCell>
                  <TableCell align="right">{sale.quantity}</TableCell>
                  <TableCell align="right">{sale.discount}%</TableCell>
                  <TableCell align="right">{money(sale.total_display, sale.currency)}</TableCell>
                  <TableCell align="right">
                    {sale.type === "invoice" && (
                      <Button size="small" startIcon={<ReplayOutlinedIcon />} onClick={() => refundSale(sale)}>Refund</Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
              {!sales.length && (
                <TableRow>
                  <TableCell colSpan={7}><EmptyState label="No sales documents yet." /></TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <AppSnackbar open={toast.open} severity={toast.severity} message={toast.message} onClose={() => setToast({ ...toast, open: false })} />
    </Container>
  );
};

export default SalesPage;
