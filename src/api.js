import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8000/api',  // Adjust if your Laravel backend URL is different
  withCredentials: true, // To send cookies if needed for sanctum
});

export default api;
