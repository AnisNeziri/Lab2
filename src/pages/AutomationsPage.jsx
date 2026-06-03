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
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import NotificationsActiveOutlinedIcon from "@mui/icons-material/NotificationsActiveOutlined";
import api from "../services/api";
import EmptyState from "../components/EmptyState";
import AppSnackbar from "../components/AppSnackbar";

const rulesKey = "aims_automation_rules";
const reportsKey = "aims_scheduled_reports";
const webhooksKey = "aims_webhooks";

const readStorage = (key) => {
  try {
    return JSON.parse(localStorage.getItem(key)) || [];
  } catch {
    return [];
  }
};

const writeStorage = (key, value) => localStorage.setItem(key, JSON.stringify(value));

const AutomationsPage = () => {
  const [products, setProducts] = useState([]);
  const [rules, setRules] = useState([]);
  const [reports, setReports] = useState([]);
  const [webhooks, setWebhooks] = useState([]);
  const [toast, setToast] = useState({ open: false, severity: "info", message: "" });
  const [ruleForm, setRuleForm] = useState({ threshold: 5, email: "", auto_po: true });
  const [reportForm, setReportForm] = useState({ name: "Weekly sales report", frequency: "weekly", channel: "email" });
  const [webhookForm, setWebhookForm] = useState({ name: "", url: "", event: "stock.low" });

  useEffect(() => {
    setRules(readStorage(rulesKey));
    setReports(readStorage(reportsKey));
    setWebhooks(readStorage(webhooksKey));
    const loadProducts = async () => {
      try {
        const res = await api.get("/products");
        setProducts(Array.isArray(res.data) ? res.data : []);
      } catch {
        setToast({ open: true, severity: "warning", message: "Products could not load for rules preview." });
      }
    };
    loadProducts();
  }, []);

  const lowStockMatches = useMemo(
    () => products.filter((product) => Number(product.quantity || 0) < Number(ruleForm.threshold || 0)),
    [products, ruleForm.threshold]
  );

  const saveRules = (nextRules) => {
    setRules(nextRules);
    writeStorage(rulesKey, nextRules);
  };

  const saveReports = (nextReports) => {
    setReports(nextReports);
    writeStorage(reportsKey, nextReports);
  };

  const saveWebhooks = (nextWebhooks) => {
    setWebhooks(nextWebhooks);
    writeStorage(webhooksKey, nextWebhooks);
  };

  const addRule = () => {
    saveRules([{ ...ruleForm, id: Date.now(), active: true, created_at: new Date().toISOString() }, ...rules]);
    setToast({ open: true, severity: "success", message: "Automation rule saved." });
  };

  const addReport = () => {
    saveReports([{ ...reportForm, id: Date.now(), active: true }, ...reports]);
    setToast({ open: true, severity: "success", message: "Scheduled report saved." });
  };

  const addWebhook = () => {
    if (!webhookForm.name || !webhookForm.url) return;
    saveWebhooks([{ ...webhookForm, id: Date.now(), active: true }, ...webhooks]);
    setWebhookForm({ name: "", url: "", event: "stock.low" });
    setToast({ open: true, severity: "success", message: "Webhook registered." });
  };

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      <Box sx={{ display: "flex", justifyContent: "space-between", gap: 2, flexWrap: "wrap", mb: 3 }}>
        <Box>
          <Typography variant="overline" color="text.secondary">Notifications and integrations</Typography>
          <Typography variant="h4" sx={{ fontWeight: 700 }}>Automations</Typography>
        </Box>
        <Chip icon={<NotificationsActiveOutlinedIcon />} label={`${lowStockMatches.length} products match current rule`} color="warning" variant="outlined" />
      </Box>

      <Grid container spacing={2} sx={{ mb: 3 }}>
        <Grid item xs={12} md={4}>
          <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>Rules Engine</Typography>
              <Stack spacing={2}>
                <TextField label="Low Stock Threshold" type="number" value={ruleForm.threshold} onChange={(e) => setRuleForm({ ...ruleForm, threshold: e.target.value })} />
                <TextField label="Notify Email" value={ruleForm.email} onChange={(e) => setRuleForm({ ...ruleForm, email: e.target.value })} />
                <Box sx={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
                  <Typography>Create PO automatically</Typography>
                  <Switch checked={ruleForm.auto_po} onChange={(e) => setRuleForm({ ...ruleForm, auto_po: e.target.checked })} />
                </Box>
                <Button variant="contained" onClick={addRule}>Save Rule</Button>
              </Stack>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} md={4}>
          <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>Scheduled Reports</Typography>
              <Stack spacing={2}>
                <TextField label="Report Name" value={reportForm.name} onChange={(e) => setReportForm({ ...reportForm, name: e.target.value })} />
                <TextField select label="Frequency" value={reportForm.frequency} onChange={(e) => setReportForm({ ...reportForm, frequency: e.target.value })}>
                  <MenuItem value="daily">Daily</MenuItem>
                  <MenuItem value="weekly">Weekly</MenuItem>
                  <MenuItem value="monthly">Monthly</MenuItem>
                </TextField>
                <TextField select label="Channel" value={reportForm.channel} onChange={(e) => setReportForm({ ...reportForm, channel: e.target.value })}>
                  <MenuItem value="email">Email</MenuItem>
                  <MenuItem value="in-app">In-app</MenuItem>
                  <MenuItem value="push">Push</MenuItem>
                </TextField>
                <Button variant="contained" onClick={addReport}>Schedule</Button>
              </Stack>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} md={4}>
          <Card sx={{ height: "100%", border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>Webhooks</Typography>
              <Stack spacing={2}>
                <TextField label="Webhook Name" value={webhookForm.name} onChange={(e) => setWebhookForm({ ...webhookForm, name: e.target.value })} />
                <TextField label="Target URL" value={webhookForm.url} onChange={(e) => setWebhookForm({ ...webhookForm, url: e.target.value })} />
                <TextField select label="Event" value={webhookForm.event} onChange={(e) => setWebhookForm({ ...webhookForm, event: e.target.value })}>
                  <MenuItem value="stock.low">Stock low</MenuItem>
                  <MenuItem value="sale.created">Sale created</MenuItem>
                  <MenuItem value="po.received">PO received</MenuItem>
                </TextField>
                <Button variant="contained" onClick={addWebhook}>Add Webhook</Button>
              </Stack>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Grid container spacing={2}>
        <Grid item xs={12} md={6}>
          <Card sx={{ border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>Active Rules</Typography>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Condition</TableCell>
                    <TableCell>Action</TableCell>
                    <TableCell>Status</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {rules.map((rule) => (
                    <TableRow key={rule.id}>
                      <TableCell>Stock below {rule.threshold}</TableCell>
                      <TableCell>{rule.auto_po ? "Email + auto PO" : "Email only"}</TableCell>
                      <TableCell><Chip size="small" color="success" label="Active" variant="outlined" /></TableCell>
                    </TableRow>
                  ))}
                  {!rules.length && <TableRow><TableCell colSpan={3}><EmptyState label="No automation rules yet." /></TableCell></TableRow>}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} md={6}>
          <Card sx={{ border: "1px solid", borderColor: "divider" }}>
            <CardContent>
              <Typography variant="h6" sx={{ fontWeight: 700, mb: 2 }}>Scheduled Jobs & Webhooks</Typography>
              <Stack spacing={1}>
                {[...reports, ...webhooks].map((item) => (
                  <Box key={`${item.name}-${item.id}`} sx={{ display: "flex", justifyContent: "space-between", gap: 2, borderBottom: "1px solid", borderColor: "divider", py: 1 }}>
                    <Typography>{item.name}</Typography>
                    <Chip size="small" label={item.frequency || item.event} variant="outlined" />
                  </Box>
                ))}
                {![...reports, ...webhooks].length && <EmptyState label="No scheduled jobs or webhooks yet." />}
              </Stack>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <AppSnackbar open={toast.open} severity={toast.severity} message={toast.message} onClose={() => setToast({ ...toast, open: false })} />
    </Container>
  );
};

export default AutomationsPage;
