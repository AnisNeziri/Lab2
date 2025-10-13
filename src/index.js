import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import CssBaseline from '@mui/material/CssBaseline';
import { ThemeProvider, createTheme } from '@mui/material/styles';
import reportWebVitals from './reportWebVitals';
import './index.css';

// Create a MUI theme (customize colors if needed)
const theme = createTheme({
  palette: {
    primary: {
      main: '#1976d2', // default blue
    },
    secondary: {
      main: '#ff4b2b', // matching your gradient
    },
  },
  typography: {
    fontFamily: 'Montserrat, Arial, sans-serif',
  },
});

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(
  <React.StrictMode>
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <App />
    </ThemeProvider>
  </React.StrictMode>
);

// If you want to measure performance in your app
reportWebVitals();
