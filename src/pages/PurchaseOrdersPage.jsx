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
import LocalShippingOutlinedIcon from "@mui/icons-material/LocalShippingOutlined";
import MailOutlineIcon from "@mui/icons-material/MailOutline";
import api from "../services/api";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const storageKey = "aims_purchase_orders";
const currency = new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" });

const defaultOrder = {
  supplier_id: "",
  product_id: "",
  quantity: 1,
  received_quantity: 0,
  unit_cost: 0,
  expected_date: "",
  status: "draft",
  notes: "",
};

const readOrders = () => {
  try {
    return JSON.parse(localStorage.getItem(storageKey)) || [];
  } catch {
    return [];
  }
};

const writeOrders = (orders) => localStorage.setItem(storageKey, JSON.stringify(orders));

const PurchaseOrdersPage = () => {
  const [orders, setOrders] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [products, setProducts] = useState([]);
  const [open, setOpen] = useState(false);
  const [receiveOrder, setReceiveOrder] = useState(null);
  const [receiveQuantity, setReceiveQuantity] = useState(1);
  const [form, setForm] = useState(defaultOrder);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });

  useEffect(() => {
    setOrders(readOrders());
    const load = async () => {
      try {
        const [supplierRes, productRes] = await Promise.all([api.get("/suppliers"), api.get("/products")]);
        setSuppliers(Array.isArray(supplierRes.data) ? supplierRes.data : []);
        setProducts(Array.isArray(productRes.data) ? productRes.data : []);
      } catch {
        setToast({ open: true, severity: "warning", message: "Suppliers or products could not load." });
      }
    };
    load();
  }, []);

  const supplierName = (id) => suppliers.find((supplier) => String(supplier.id) === String(id))?.name || "Supplier";
  const product = (id) => products.find((item) => String(item.id) === String(id));
  const productName = (id) => product(id)?.name || "Product";

  const metrics = useMemo(() => {
    const totalCost = orders.reduce((sum, order) => sum + Number(order.quantity) * Number(order.unit_cost), 0);
    const openOrders = orders.filter((order) => ["sent", "partial", "draft"].includes(order.status)).length;
    const backorders = orders.filter((order) => Number(order.received_quantity) < Number(order.quantity) && order.status !== "draft").length;
    return { totalCost, openOrders, backorders };
  }, [orders]);

  const saveOrders = (nextOrders) => {
    setOrders(nextOrders);
    writeOrders(nextOrders);
  };

  const createOrder = () => {
    const nextOrder = {
      ...form,
      id: Date.now(),
      quantity: Number(form.quantity),
      received_quantity: 0,
      unit_cost: Number(form.unit_cost),
      created_at: new Date().toISOString(),
    };
    saveOrders([nextOrder, ...orders]);
    setForm(defaultOrder);
    setOpen(false);
    setToast({ open: true, severity: "success", message: "Purchase order created." });
  };

  const sendOrder = (order) => {
    const nextOrders = orders.map((item) => item.id === order.id ? { ...item, status: "sent", sent_at: new Date().toISOString() } : item);
    saveOrders(nextOrders);
    setToast({ open: true, severity: "success", message: "PO marked as sent. PDF/email workflow prepared." });
  };

  const receiveStock = async () => {
    if (!receiveOrder) return;
    const quantity = Math.max(1, Number(receiveQuantity));
    const totalReceived = Math.min(Number(receiveOrder.quantity), Number(receiveOrder.received_quantity || 0) + quantity);
    const status = totalReceived >= Number(receiveOrder.quantity) ? "received" : "partial";
    const nextOrders = orders.map((order) =>
      order.id === receiveOrder.id ? { ...order, received_quantity: totalReceived, status } : order
    );

    try {
      await api.post("/transactions/purchase", {
        product_id: Number(receiveOrder.product_id),
        quantity,
        notes: `Receiving for PO-${receiveOrder.id}`,
      });
      setToast({ open: true, severity: "success", message: "Receiving saved and stock updated." });
    } catch {
      setToast({ open: true, severity: "warning", message: "Receiving saved locally. Backend stock update failed." });
    }

    saveOrders(nextOrders);
    setReceiveOrder(null);
    setReceiveQuantity(1);
  };

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      <Box sx={{ display: "flex", justifyContent: "space-between", gap: 2, flexWrap: "wrap", mb: 3 }}>
        <Box>
          <Typography variant="overline" color="text.secondary">Purchasing</Typography>
          <Typography variant="h4" sx={{ fontWeight: 700 }}>Purchase Orders</Typography>
        </Box>
        <Button variant="contained" onClick={() => setOpen(true)}>Create PO</Button>
      </Box>

      <Grid container spacing={2} sx={{ mb: 3 }}>
        {[
          ["Open orders", metrics.openOrders],
          ["Backorders", metrics.backorders],
          ["Committed cost", currency.format(metrics.totalCost)],
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
                <TableCell>PO</TableCell>
                <TableCell>Supplier</TableCell>
                <TableCell>Product</TableCell>
                <TableCell align="right">Ordered</TableCell>
                <TableCell align="right">Received</TableCell>
                <TableCell align="right">Backorder</TableCell>
                <TableCell align="right">Cost</TableCell>
                <TableCell>Status</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {orders.map((order) => {
                const backorder = Math.max(0, Number(order.quantity) - Number(order.received_quantity || 0));
                return (
                  <TableRow key={order.id}>
                    <TableCell sx={{ fontWeight: 700 }}>PO-{order.id}</TableCell>
                    <TableCell>{supplierName(order.supplier_id)}</TableCell>
                    <TableCell>{productName(order.product_id)}</TableCell>
                    <TableCell align="right">{order.quantity}</TableCell>
                    <TableCell align="right">{order.received_quantity || 0}</TableCell>
                    <TableCell align="right">{backorder}</TableCell>
                    <TableCell align="right">{currency.format(Number(order.quantity) * Number(order.unit_cost))}</TableCell>
                    <TableCell>
                      <Chip size="small" label={order.status} color={order.status === "received" ? "success" : order.status === "partial" ? "warning" : "primary"} variant="outlined" />
                    </TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={1} justifyContent="flex-end">
                        <Button size="small" startIcon={<MailOutlineIcon />} onClick={() => sendOrder(order)}>Send</Button>
                        <Button size="small" startIcon={<LocalShippingOutlinedIcon />} onClick={() => { setReceiveOrder(order); setReceiveQuantity(backorder || 1); }}>Receive</Button>
                      </Stack>
                    </TableCell>
                  </TableRow>
                );
              })}
              {!orders.length && (
                <TableRow>
                  <TableCell colSpan={9}><EmptyState label="No purchase orders yet." /></TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <DialogTitle>Create Purchase Order</DialogTitle>
        <DialogContent>
          <Box sx={{ display: "grid", gap: 2, mt: 1 }}>
            <TextField select label="Supplier" value={form.supplier_id} onChange={(e) => setForm({ ...form, supplier_id: e.target.value })}>
              {suppliers.map((supplier) => <MenuItem key={supplier.id} value={supplier.id}>{supplier.name}</MenuItem>)}
            </TextField>
            <TextField select label="Product" value={form.product_id} onChange={(e) => {
              const selected = product(e.target.value);
              setForm({ ...form, product_id: e.target.value, unit_cost: Number(selected?.buying_price || form.unit_cost) });
            }}>
              {products.map((item) => <MenuItem key={item.id} value={item.id}>{item.name} ({item.sku})</MenuItem>)}
            </TextField>
            <TextField label="Quantity" type="number" inputProps={{ min: 1 }} value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
            <TextField label="Unit Cost" type="number" value={form.unit_cost} onChange={(e) => setForm({ ...form, unit_cost: e.target.value })} />
            <TextField label="Expected Date" type="date" InputLabelProps={{ shrink: true }} value={form.expected_date} onChange={(e) => setForm({ ...form, expected_date: e.target.value })} />
            <TextField label="Notes" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button variant="contained" onClick={createOrder} disabled={!form.supplier_id || !form.product_id}>Save</Button>
        </DialogActions>
      </Dialog>

      <Dialog open={Boolean(receiveOrder)} onClose={() => setReceiveOrder(null)} fullWidth maxWidth="xs">
        <DialogTitle>Receive Stock</DialogTitle>
        <DialogContent>
          <TextField
            sx={{ mt: 1 }}
            fullWidth
            label="Received Quantity"
            type="number"
            inputProps={{ min: 1 }}
            value={receiveQuantity}
            onChange={(e) => setReceiveQuantity(e.target.value)}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setReceiveOrder(null)}>Cancel</Button>
          <Button variant="contained" onClick={receiveStock}>Confirm Receiving</Button>
        </DialogActions>
      </Dialog>

      <AppSnackbar open={toast.open} severity={toast.severity} message={toast.message} onClose={() => setToast({ ...toast, open: false })} />
    </Container>
  );
};

export default PurchaseOrdersPage;
