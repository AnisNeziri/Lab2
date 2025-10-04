import React, { useEffect, useState } from 'react';
import {
  Container, Typography, Table, TableBody, TableCell, TableHead,
  TableRow, Button, Box, Select, MenuItem
} from '@mui/material';
import api from '../services/api';

const UsersPage = ({ onLogout, user }) => {
  const [users, setUsers] = useState([]);
  const [roles, setRoles] = useState([]);
  const [selectedRoles, setSelectedRoles] = useState({});

  const fetchUsers = async () => {
    try {
      const res = await api.get('/users');
      setUsers(res.data);
    } catch {
      alert('Failed to fetch users');
    }
  };

  const fetchRoles = async () => {
    try {
      const res = await api.get('/roles');
      setRoles(res.data);
    } catch {
      alert('Failed to fetch roles');
    }
  };

  useEffect(() => {
    fetchUsers();
    fetchRoles();
  }, []);

  const handleRoleChange = (userId, role) => {
    setSelectedRoles(prev => ({ ...prev, [userId]: role }));
  };

  const assignRole = async (userId) => {
    if (!selectedRoles[userId]) return alert('Select a role first');
    try {
      await api.post(`/users/${userId}/assign-role`, { role: selectedRoles[userId] });
      alert('Role assigned');
      fetchUsers();
    } catch {
      alert('Failed to assign role');
    }
  };

  const removeRole = async (userId) => {
    if (!selectedRoles[userId]) return alert('Select a role first');
    try {
      await api.post(`/users/${userId}/remove-role`, { role: selectedRoles[userId] });
      alert('Role removed');
      fetchUsers();
    } catch {
      alert('Failed to remove role');
    }
  };

  return (
    <Container>
      <Box sx={{ mt: 3, display: 'flex', justifyContent: 'space-between' }}>
        <Typography variant="h4">User Management</Typography>
        <Button variant="outlined" color="secondary" onClick={onLogout}>
          Logout
        </Button>
      </Box>
      <Typography variant="subtitle1" sx={{ mb: 2 }}>
        Logged in as: {user?.name} ({user?.email})
      </Typography>

      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Name</TableCell>
            <TableCell>Email</TableCell>
            <TableCell>Role</TableCell>
            <TableCell>Actions</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {users.map(u => (
            <TableRow key={u.id}>
              <TableCell>{u.name}</TableCell>
              <TableCell>{u.email}</TableCell>
              <TableCell>
                <Select
                  value={selectedRoles[u.id] || ''}
                  onChange={(e) => handleRoleChange(u.id, e.target.value)}
                >
                  {roles.map(role => (
                    <MenuItem key={role} value={role}>{role}</MenuItem>
                  ))}
                </Select>
              </TableCell>
              <TableCell>
                <Button onClick={() => assignRole(u.id)} variant="contained" color="primary" size="small" sx={{ mr: 1 }}>
                  Assign
                </Button>
                <Button onClick={() => removeRole(u.id)} variant="outlined" color="error" size="small">
                  Remove
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Container>
  );
};

export default UsersPage;
